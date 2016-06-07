Redis Tagging
=====

Redis Tagging is a simple, fast open source PHP Tagging System.

### Install

If you have Composer, just include RedisTagging as a project dependency in your `composer.json`. If you don't just install it by downloading the .ZIP file and extracting it to your project directory.

```
require: {
    "tags/php-redis-tagging": "dev-master"
}
```

### Examples

First, `use` the RedisTagging namespace:

```PHP
public function demo()
{
    $redisTagging = new RedisTagging();

    // optional,default to "default"
    $redisTagging->bucket("default");

    // get a tag
    $result = $redisTagging->get('tag1');

    // want page limit ?
    $result = $redisTagging->limit(10)->offset(0)->get('tag1');

    // get interset of tags
    $result = $redisTagging->limit(10)->offset(10)->type('inter')->get(['tag1', 'tag2']);

    // order ?
    $result = $redisTagging->limit(10)->offset(10)->type('inter')->order('desc')->get(['tag1', 'tag2']);

    // get hottest tags
    $result = $redisTagging->topTags(3);

}

```

more information,read the code please or issue me , it's quite simple

<hr>

