<?php
/**
 * @Author: feixiang.wu
 * @Time: 2016-06-06 10:25
 * A PHP Redis Tagging System
 *
 * How it works?
 *  First,We set each tag in a set which contains its members into a sorted set
 *      ZADD tag1 "test.com" $score1 "test1.com" $score2
 *  Then, we should maintain our tags in a normail set
 *      SADD TAGS tag1 ...
 *
 *  If you want to know which tag is hottest,we can use another sorted set which contains our tags and its numbers of
 *     values Then we will get the hottest tags , the rank of tags and ...
 * @usage
 *   please check out the demo below
 */

namespace Tags;


class RedisTagging
{
    /**
     * Redis Instance
     * @var
     */
    private $redis;

    /**
     * Redis Config
     * @var array
     */
    protected static $defaultConfig = [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => NULL,
        'timeout'  => 0
    ];

    /**
     * bucket for namespace
     * @var string
     */
    private $bucket = "default";

    /**
     * tags will find
     * @var string|array
     */
    private $tags;

    /**
     * page limit
     * @var int
     */
    private $limit = 20;

    /**
     * record offset
     * @var int default to 0
     */
    private $offset = 0;

    /**
     * sorting asc|desc
     * @var string
     */
    private $order = 'desc';

    /**
     * set of values, inter|union|diff
     * @var string
     */
    private $type = 'union';

    /**
     * params for interset and union set
     * the weights will be multiplied by the score of each element in the sorted set
     * @var array
     */
    private $weight = null;

    /**
     * params for interset and union set
     * the results of the union are aggregated, SUM|MIN|MAX
     * @var string
     */
    private $aggregate = "MIN";

    /**
     * scores for sorting
     * @var bool
     */
    private $withscores = true;

    /**
     * score of tags
     * @var null
     */
    private $score = null;

    /**
     * the name of TAGS set
     */
    const TAGS = "TAGS";

    function __construct($config = null)
    {
        if (!empty($config)) {
            $config = array_merge(self::$defaultConfig, $config);
        } else {
            $config = self::$defaultConfig;
        }
        $this->connect($config);
    }

    /**
     * demo usage
     */
    public function demo()
    {
        $redisTagging = new RedisTagging();

        // optional,default to "default"
        $redisTagging->bucket("default");

        // set a tag
        $tags = [
            'tag1' => [
                ['member' => "a1", 'score' => 2],
                ['member' => "b2", 'score' => 4],
            ],
            'tag2' => [
                ['member' => "a1", 'score' => 2],
                ['member' => "b2", 'score' => 4],
                ['member' => "b3", 'score' => 3]
            ]
        ];
        foreach ($tags as $tag => $item) {
            $redisTagging->set($tag, $item);
        }

        // get a tag
        $result = $redisTagging->get('tag1');
        // want page limit ?
        $result = $redisTagging->offset(0)->limit(10)->get('tag1');
        // get interset of tags , limit 0 means all
        $result = $redisTagging->offset(10)->limit(0)->type('inter')->get(['tag1', 'tag2']);
        // order ?
        $result = $redisTagging->offset(10)->limit(10)->type('inter')->order('desc')->get(['tag1', 'tag2']);
        // get hottest tags
        $result = $redisTagging->topTags(3);
        //remove all tags
        //$result = $redisTagging->removeAll();

    }

    /**
     * Setup Redis
     * @param null $config
     * @throws \Exception
     */
    private function connect($config)
    {
        $this->redis = new \Redis();
        if (!$this->redis->connect($config['host'], $config['port'], $config['timeout'])) {
            throw new \Exception("Failed to connect to redis");
        }

        if (!empty($config['password']) && !$this->redis->auth($config['password'])) {
            throw new \Exception("Redis Auth Failed!");
        }
    }

    /**
     * Get values of tag(s)
     * @param $tags
     * @return mixed
     */
    public function get($tags = null)
    {
        empty($tags) && $tags = $this->tags;

        $key = $tmpKey = '';
        if (is_string($tags)) {
            // if string , get it directly
            $key = $this->getKey("TAG:{$tags}");
        } else if (is_array($tags)) {
            if (count($tags) == 1) {
                $key = $this->getKey("TAG:{$tags[0]}");
            } else {
                // add prefix to each tag
                foreach ($tags as $i => $tag) {
                    $tags[$i] = $this->getKey("TAG:{$tag}");
                }
            }
        }

        $redis = $this->redis->multi();
        $multiTags = count($tags) > 1;

        if ($multiTags) {
            $tmpKey = $key . '_' . time() . rand(1000, 9999);
            $key = $tmpKey;

            $type = ucwords($this->type);
            $cmd = 'z' . $type;
            $redis->$cmd($tmpKey, $tags, $this->weight, $this->aggregate);
        }
        // get result
        if ($this->order == 'desc') {
            $redis->zRevRange($key, $this->offset, $this->limit, $this->withscores);
        } else {
            $redis->zRange($key, $this->offset, $this->limit, $this->withscores);
        }

        if ($multiTags && !empty($tmpKey)) {
            // delete tmp set after interset or union set
            $redis->del($tmpKey);
        }
        $result = $redis->exec();

        // in Redis multi mode, result is an array like : $result = [true,rows,true];
        return $multiTags ? $result[1] : $result[0];
    }


    /**
     * set tag items for a tag
     * @param $tag
     * @param $item
     * @return mixed
     */
    public function set($tag, $item)
    {
        $key = $this->getKey("TAG:{$tag}");
        $score = 1;
        $redis = $this->redis->multi();

        if (isset($item[0]) && is_array($item[0])) {
            foreach ($item as $i) {
                $redis->zAdd($key, $i['score'], $i['member']);
            }
            $score = count($item);
        } else {
            $redis->zAdd($key, $item['score'], $item['member']);
        }

        // add tag into Tags set
        $redis->zAdd($this->getKey(self::TAGS), $score, $tag);
        $ret = $this->redis->exec();

        return $ret;
    }

    /**
     * get number of a specify tag
     * @param $tag
     * @return int
     */
    public function count($tag)
    {
        return $this->redis->zCard($this->getKey("TAG:{$tag}"));
    }

    /**
     * Remove a Tag from bullet
     * @param $tag
     */
    public function remove($tag)
    {
        $key = $this->getKey("TAG:{$tag}");

        return $this->redis->del($key);
    }


    /**
     * check if tag exists
     * @param $tag
     * @return bool
     */
    public function exists($tag)
    {
        $key = $this->getKey("TAG:{$tag}");

        return $this->redis->zRank($key);
    }

    /**
     * remove all tags of a bullet
     * @param string $bucket
     * @return bool
     */
    public function removeAll()
    {
        $tags = $this->limit(0)->withscores(false)->getTags();
        if (!empty($tags)) {
            $redis = $this->redis->multi();
            // remove all tag sets
            foreach ($tags as $tag) {
                $key = $this->getKey("TAG:{$tag}");
                $redis->del($key);
            }

            // remove tags record
            $key = $this->getKey(self::TAGS);
            $redis->del($key);

            $redis->exec();
        }
        return true;
    }

    /**
     * @todo remove or edit a item and update relative tags
     * Remove a tag Item
     * It may be a little difficult if tag haven't been given , because we don't know where the item is?
     *
     * @param $item
     * @return bool
     */
    public function removeTagItem($tag, $item)
    {
        $key = $this->getKey("TAG:{$tag}");
        if (is_array($item)) {
            $redis = $this->redis->multi();
            foreach ($item as $i) {
                $redis->zRem($key, $i);
            }
            $ret = $this->redis->exec();
        } else {
            $ret = $this->redis->zRem($key, $item);
        }

        return $ret;
    }

    public function tags($tags)
    {
        $this->tags = $tags;

        return $this;
    }

    /**
     * save tag
     * @param $tag
     * @return bool
     */
    public function save($tag)
    {
        $key = $this->getKey(self::TAGS);

        return $this->redis->sAdd($key, $tag);
    }

    /**
     * get tags
     * @return mixed
     */
    public function getTags()
    {
        $key = $this->getKey(self::TAGS);
        $result = $this->redis->zRange($key, $this->offset, $this->limit, $this->withscores);

        return $result;
    }

    /**
     * get
     * @param int $n
     * @return array
     */
    public function topTags($n = 1)
    {
        $key = $this->getKey(self::TAGS);
        if (empty($this->score)) {
            $this->limit($n);
            $result = $this->redis->zRevRange($key, $this->offset, $this->limit, $this->withscores);
        } else {
            $result = $this->redis->zRevRangeByScore($key, $this->score, INF,
                [
                    'withscores' => $this->withscores,
                    'limit'      => [$this->offset, $n]
                ]
            );
        }

        return $result;
    }

    public function limit($n)
    {
        if( $n === 0 ){
            $this->limit = -1 ;
        }else{
            $this->limit = $this->offset + $n - 1;
        }

        return $this;
    }

    public function offset($n)
    {
        $this->offset = $n;
        if ($this->offset < 0) {
            $this->offset = 0;
        }

        return $this;
    }

    public function order($order)
    {
        $orders = ['desc', 'asc'];
        if (!in_array($order, $orders)) {
            throw new \Exception('Order not supported!');
        }
        $this->order = $order;

        return $this;
    }

    public function type($type, $weight = null, $aggregate = "MIN")
    {
        $types = ['inter', 'union', 'diff'];
        if (!in_array($type, $types)) {
            throw new \Exception('Type not supported!');
        }
        $this->type = $type;
        $this->weight = $weight;
        $this->aggregate = $aggregate;

        return $this;
    }

    public function score($n)
    {
        $this->score = $n;

        return $this;
    }

    public function withscores($bool)
    {
        $this->withscores = $bool;

        return $this;
    }

    /**
     * get key with namespace
     * @param $name
     * @return string
     */
    private function getKey($name)
    {
        return $this->bucket . ':' . $name;
    }

}