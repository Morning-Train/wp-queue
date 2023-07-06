# Morningtrain\WP\Queue

Queue system for WordPress.

## Table of Contents

- [Introduction](#introduction)
- [Getting Started](#getting-started)
  - [Installation](#installation)
- [Usage](#usage)
  - [Register Worker](#register-worker)
  - [Create a Job](#create-a-job)
  - [Running the Queue Worker](#running-the-queue-worker)
- [Credits](#credits)
- [License](#license)

## Introduction

This package is made to dispatch jobs to a queue system.

It is implementet with WP CLI so it can be runned from the command line.

## Getting started

To get started install the package as described below in [Installation](#installation).

To use the package have a look at [Usage](#usage)

### Installation

Install with composer.

```composer require morningtrain/wp-queue```

## Usage

### Register Worker

To get started with the module simply register a job queue `\Morningtrain\WP\Queue\Queue::registerWorker()`.

You can change name of the job queue from default `job_queue`.
You can change the version of the job queue database table from default `1.0.0`.

```php
\Morningtrain\WP\Queue\Queue::registerWorker();
```

### Create a Job
Jobs can be created by extending `Morningtrain\WP\Queue\Abstracts\AbstractJob` and create a `handle` method.

You can define which job_queue the job should use by setting the `$worker` parameter to the job queue slug.

Now you can put a job in the queue by calling the static method `dispatch` on your Job class.

```php
// Job/TestJob.php
use Morningtrain\WP\Queue\Abstracts\AbstractJob;

class TestJob extends AbstractJob {

  public static function handle(mixed $args) : mixed
  {
    // Do something
    return 'testing...';
  }
}
```

```php 
// Another file
TestJob::dispatch('test_arg');
```

#### Alternatively Job Creation
Jobs can alternativley be created directly from the worker, by passing a callback to the `createJob` method.

```php
\Morningtrain\WP\Queue\Classes\Worker::getWorker('job_queue')->createJob($callback, $args);
```

#### Arguments
If you use arguments in your jobs, you have to be aware, that we are using `call_user_func_array`. 
So if you pass an array, you need to have same number of arguments in your method as you have in your array. And if keyed, the arguments in the method must be called the same as the keys.

If you do not know the number of arguments in your array or you just need to work on an data array, you can use `...$args` in your method.
Or you can wrap your array in an extra array containing only the one argument.

See more about `call_user_func_arrey` on the [PHP documentation](https://www.php.net/manual/en/function.call-user-func-array.php)

### Running the Queue Worker
Use WP CLI to run the job queue. See [WP CLI documentation](https://wp-cli.org/).

#### Start Worker

Start job queue with the `wp queue start` command and the worker slug.

```
wp queue start job_queue
```

> **Note**
> 
> The worker will run until it is manually stopped or the terminal is closed. 
> You should use a process monitor such as [Supervisor](http://supervisord.org/index.html) to make sure the process does not stop unintended.

You can run multiple workers simultaneously. 

#### Stop Worker

To stop a worker that you can not stop by closing you terminal, you can use the `stop` command with the worker slug.

```
wp queue stop job_queue
```

To stop all workers use `all` instead of worker slug.

```
wp queue stop all
```

#### List available workers
To list available workers and their status, use the `list` command

```
wp queue list
```

#### Process a Specific Job

Call `wp queue run` with the worker slug and the job ID to process the specific job.

```
wp queue run job_queue 99
```

> **Note**
>
> You can use --untouched to not set run_date and result
> ```wp queue run jon_queue 99 --untouched```
> 
> You can use --force to force the job to run even though it has run before
> ```wp queue run jon_queue 99 --force```

## Credits

- [Martin Schadegg Brønniche](https://github.com/mschadegg)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.