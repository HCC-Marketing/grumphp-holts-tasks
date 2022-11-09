<?php

namespace Holtsdev\GrumphpTasks\Task;

use GrumPHP\Runner\TaskResult;
use GrumPHP\Runner\TaskResultInterface;
use GrumPHP\Task\AbstractExternalTask;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitCommitMsgContext;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Finder\SplFileInfo;

class WhitespaceCheckerTask extends AbstractExternalTask
{
    const TAG_REGEX = "/[A-Z0-9-]+\s+\[whitespace\]/i";
    const TAG_DISPLAY_TEXT = '[Whitespace]';
    
    public function getName(): string
    {
        return 'holtsdev_whitespace_checker';
    }

    static public function getConfigurableOptions(): OptionsResolver
    {
        $resolver = new OptionsResolver();

        $resolver->setDefaults([
            'triggered_by' => ['php', 'phtml', 'xml', 'yml', 'js', 'less', 'css'],
        ]);

        $resolver->addAllowedTypes('triggered_by', ['array']);

        return $resolver;
    }

    public function canRunInContext(ContextInterface $context): bool
    {
        return $context instanceof GitCommitMsgContext;
    }

    public function run(ContextInterface $context): TaskResultInterface
    {
        $config = $this->getConfig()->getOptions();

        $files = $context->getFiles()->extensions($config['triggered_by']);

        if (count($files) === 0) {
            return TaskResult::createSkipped($this, $context);
        }

        $matchedWhitespaceCommitMsg = preg_match(self::TAG_REGEX, $context->getCommitMessage());

        if ($matchedWhitespaceCommitMsg === false) {
            return TaskResult::createFailed($this, $context, sprintf(
                'Failed to check commit message:%s%s',
                PHP_EOL,
                preg_last_error_msg()
            ));
        }

        /*
         * This algorithm is more complicated than originally intended.
         * 
         * Originally, --name-only was used, since only a list of names was
         * desired. However, contrary to the docs, --name-only results in the
         * --ignore-all-space flag being ignored. That's out, and that leaves
         * a much less clean solution.
         * 
         * Additionally, --exit-code was used to quickly decide whether any
         * non-whitespace changes were present. However, --exit-code always
         * resulted in a code of 0 unless --quiet was also specified, even
         * with --no-pager. Since it didn't work as documented, that's out.
         * 
         * Modify with care.
         */
        $arguments = $this->processBuilder->createArgumentsForCommand('git');
        $arguments->add('diff');
        $arguments->add('--cached');
        $arguments->add('--numstat');
        $arguments->add('--ignore-all-space');
        $arguments->add('-z');
        $arguments->addFiles($files);

        $process = $this->processBuilder->buildProcess($arguments);
        $process->run();

        if ($process->getExitCode() !== 0) {
            return TaskResult::createFailed($this, $context, sprintf(
                'Failed to compare staged changes:%s%s',
                PHP_EOL,
                $process->getErrorOutput()
            ));
        }

        $output = $this->formatter->format($process);

        $filesWithNonWhitespaceChanges = [];
        $filesWithWhitespaceOnlyChanges = [];

        foreach (explode("\0", $output) as $line) {
            if (empty($line)) {
                continue;
            }

            list($added, $removed, $path) = explode("\t", $line, 3);

            if ($added == 0 && $removed == 0) {
                $filesWithWhitespaceOnlyChanges[] = $path;
            } else {
                $filesWithNonWhitespaceChanges[] = $path;
            }
        }

        if ($matchedWhitespaceCommitMsg) {
            if (count($filesWithNonWhitespaceChanges) > 0) {
                return TaskResult::createFailed($this, $context, sprintf(
                    'Non-whitespace changes detected in commit marked whitespace only. Please unstage the files with non-whitespace changes:%s%s',
                    PHP_EOL,
                    implode(PHP_EOL, $filesWithNonWhitespaceChanges)
                ));
            }
        } else {
            if (count($filesWithNonWhitespaceChanges) === 0) {
                return TaskResult::createFailed($this, $context, sprintf(
                    'The staged changes only differ in whitespace. Please mark the commit with "%s" after the issue number for ease of auditing.',
                    self::TAG_DISPLAY_TEXT
                ));
            }

            if (count($filesWithWhitespaceOnlyChanges) > 0) {
                return TaskResult::createFailed($this, $context, sprintf(
                    'Whitespace-only changes detected in commit not marked as whitespace only. Please unstage the files with whitespace-only changes:%s%s',
                    PHP_EOL,
                    implode(PHP_EOL, $filesWithWhitespaceOnlyChanges)
                ));
            }
        }

        return TaskResult::createPassed($this, $context);
    }
}
