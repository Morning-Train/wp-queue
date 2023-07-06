<?php namespace Morningtrain\WP\Queue;

use Morningtrain\WP\Queue\Commands\QueueCommand;
use Morningtrain\WP\Queue\Classes\Worker;

class Queue {

    static bool $initialized = false;

    public static function registerWorker(string $workerSlug = 'job_queue', string $version = '1.0.0') : Worker
    {
        static::init();

        return Worker::register($workerSlug, $version);
    }

    public static function registerWorkers(array $workerSlugs, string $version = '1.0.0') : void
    {
        foreach($workerSlugs as $workerSlug) {
            static::registerWorker($workerSlug, $version);
        }
    }

    protected static function init() : void
    {
        if(static::$initialized) {
            return;
        }

        QueueCommand::register();

        static::$initialized = true;
    }

}