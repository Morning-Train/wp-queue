<?php namespace Morningtrain\WP\Queue\Abstracts;

use Morningtrain\WP\Queue\Classes\Worker;

abstract class AbstractJob {

    protected static $priority = 10;
    protected static $worker = 'job_queue';

    protected static function getCallback() {
        return 'handle';
    }

    protected static function getComponent() {
        return static::class;
    }

    protected static function getCallable() {
        return [static::getComponent(), static::getCallback()];
    }

    protected static function getPriority() {
        return static::$priority;
    }

    protected static function getWorkerSlug() {
        return static::$worker;
    }

    protected static function getWorker() {
        return Worker::getInstance(static::getWorkerSlug());
    }

    /**
     * Dispatch a job
     * @param mixed $args Arguments to send to the callback when doing the job.
     * @param string|\DateTime $date Date and time the job should run, if null it will run as soon as possible.
     * @return bool
     */
    public static function dispatch($args = null, $date = null) {
        return static::getWorker()->createJob(static::getCallable(), $args, $date, static::$priority);
    }
}