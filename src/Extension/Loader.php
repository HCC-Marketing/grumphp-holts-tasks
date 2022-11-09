<?php

namespace Holtsdev\GrumphpTasks\Extension;

use GrumPHP\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class Loader implements ExtensionInterface
{
    public function load(ContainerBuilder $container): void
    {
        $container->register('task.holtsdev_whitespace_checker', \Holtsdev\GrumphpTasks\Task\WhitespaceCheckerTask::class)
            ->addArgument(new Reference('process_builder'))
            ->addArgument(new Reference('formatter.raw_process'))
            ->addTag('grumphp.task', ['task' => 'holtsdev_whitespace_checker']);
            
        $container->register('task.holtsdev_format_php_checker', \Holtsdev\GrumphpTasks\Task\FormatPhpCheckerTask::class)
            ->addArgument(new Reference('process_builder'))
            ->addArgument(new Reference('formatter.raw_process'))
            ->addTag('grumphp.task', ['task' => 'holtsdev_format_php_checker']);
    }
}
