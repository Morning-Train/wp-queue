# Morningtrain\WP\Queue

Queue system for WordPress

## Getting started

To get started with the module simply construct an instance of `\Morningtrain\WP\Queue\Module()` and pass it to the `addModule()` method on your project instance.

You can change name of the job queue from default `job_queue` or you can pass an array with more job queue names to create more job queues.

### Example

```php
// functions.php
require __DIR__ . "/vendor/autoload.php";

use Morningtrain\WP\Core\Theme;

Theme::init();

// Add our module
Theme::getInstance()->addModule(new \Morningtrain\WP\Queue\Module());
```

## Use outside project (other modules)
You can call `\Morningtrain\WP\Queue\Module::registerJobQueue($queue_slug)` where `$queue_slug` is a slug for the queue also used for the DB table (defaults to job_queue).

### Example
```php
// Module.php
class Module extends AbstractModule {
    public function init() {
        parent::init();

        \Morningtrain\WP\Queue\Module::registerJobQueue();
    }
}
```

## Create a Job
Jobs can be created by extending `Morningtrain\WP\Queue\Abstracts\AbstractJob` and create a `handle` method, if in project context you can place it inside the `Jobs` folder.

You can define which job_queue the job should use by setting the $worker parameter to the job queue slug.

Now you can put a job in the queue by calling the static method `dispatch` on your Job class.

### Example
```php
// Job/TestJob.php

use Morningtrain\WP\Queue\Abstracts\AbstractJob;

class TestJob extends AbstractJob {

  public static function handle($args) {
    return 'testing...';
  }
}

// Another file
TestJob::dispatch('test_arg');
```

## Alternatively job creation
Jobs can alternativley be created directly from the worker, by passing a callback to the `createJob` method.

### Example
```php
\Morningtrain\WP\Queue\Classes\Worker::getWorker('job_queue')->createJob($callback, $args);
```

## Arguments
If you use arguments in your jobs, you have to be aware, that we are using `call_user_func_array`. 
So if you pass an array, you need to have same number of arguments in your method as you have in your array. And if keyed, the arguments in the method must be called the same as the keys.

If you do not know the number of arguments in your array or you just need to work on an data array, you can use `...$args` in your method.
Or you can wrap your array in an extra array containing only the one argument.

See more about `call_user_func_arrey` here: https://www.php.net/manual/en/function.call-user-func-array.php