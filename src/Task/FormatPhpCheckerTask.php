<?php

namespace Holtsdev\GrumphpTasks\Task;

use GrumPHP\Runner\TaskResult;
use GrumPHP\Runner\TaskResultInterface;
use GrumPHP\Task\AbstractExternalTask;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitCommitMsgContext;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

class FormatPhpCheckerTask extends AbstractExternalTask
{
    const TAG_REGEX = "/[A-Z0-9-]+\s+\[format\]/i";
    const TAG_DISPLAY_TEXT = '[Format]';
    
    public function getName(): string
    {
        return 'holtsdev_format_php_checker';
    }

    static public function getConfigurableOptions(): OptionsResolver
    {
        $resolver = new OptionsResolver();

        $resolver->setDefaults([
            'triggered_by' => ['php', 'phtml'],
            'fixer_path' => 'php-cs-fixer',
            'config_path' => '.php-cs-fixer.dist.php'
        ]);

        $resolver->addAllowedTypes('triggered_by', ['array']);
        $resolver->addAllowedTypes('fixer_path', ['string']);
        $resolver->addAllowedTypes('config_path', ['string']);

        return $resolver;
    }

    public function canRunInContext(ContextInterface $context): bool
    {
        return $context instanceof GitCommitMsgContext;
    }
    
    private function getFixedHash(string $filePath, string $fixerPath, string $configPath): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'fixer_');
        
        try {
            $process = Process::fromShellCommandline('git show "HEAD:$FILE_PATH" > "$TEMP_PATH"');

            $process->run(null, ['FILE_PATH' => $filePath, 'TEMP_PATH' => $tempPath]);

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(sprintf(
                    'Failed to retrieve the last committed version of the file: %s',
                    $process->getErrorOutput()
                ));
            }
            
            $process = Process::fromShellCommandline('"$FIXER_PATH" fix --quiet --config "$CONFIG_PATH" "$TEMP_PATH"');

            $process->run(null, ['FILE_PATH' => $filePath, 'FIXER_PATH' => $fixerPath, 'CONFIG_PATH' => $configPath, 'TEMP_PATH' => $tempPath]);

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(sprintf(
                    'Failed to run the fixer on the committed file: %s',
                    $process->getErrorOutput()
                ));
            }
            
            return hash_file('sha256', $tempPath, true);
        } finally {
            unlink($tempPath);
        }
    }
    
    private function getStagedHash(string $filePath): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'staged_');
        
        try {
            $process = Process::fromShellCommandline('git show ":0:$FILE_PATH" > $TEMP_PATH');

            $process->run(null, ['FILE_PATH' => $filePath, 'TEMP_PATH' => $tempPath]);

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(sprintf(
                    'Failed to retrieved the staged version of the file: %s',
                    $process->getErrorOutput()
                ));
            }
            
            return hash_file('sha256', $tempPath, true);
        } finally {
            unlink($tempPath);
        }
    }

    public function run(ContextInterface $context): TaskResultInterface
    {
        $config = $this->getConfig()->getOptions();
        $fixerPath = $config['fixer_path'];
        $configPath = $config['config_path'];

        $matchedFormatCommitMsg = preg_match(self::TAG_REGEX, $context->getCommitMessage());

        if ($matchedFormatCommitMsg === false) {
            return TaskResult::createFailed($this, $context, sprintf(
                'Failed to check commit message:%s%s',
                PHP_EOL,
                preg_last_error_msg()
            ));
        }

        $files = $context->getFiles()->extensions($config['triggered_by']);

        if (count($files) === 0) {
            return TaskResult::createSkipped($this, $context);
        }

        try {
            $filesWithNonWhitespaceChanges = [];
            $filesWithOnlyFormattingChanges = [];
            
            foreach ($files as $file) {
                $stagedHash = $this->getStagedHash($path);
                $fixedHash = $this->getFixedHash($path, $fixerPath, $configPath);

                if ($stagedHash === $fixedHash) {
                    $filesWithOnlyFormattingChanges[] = $path;
                } else {
                    $filesWithNonFormattingChanges[] = $path;
                }
            }
        } catch (\Exception $e) {
            return TaskResult::createFailed($this, $context, $e->getMessage());
        }

        if ($matchedFormatCommitMsg) {
            if (count($filesWithNonFormattingChanges) > 0) {
                return TaskResult::createFailed($this, $context, sprintf(
                    'Non-formatting changes detected in commit marked formatting only. Please unstage the files with non-formatting changes:%s%s',
                    PHP_EOL,
                    implode(PHP_EOL, $filesWithNonFormattingChanges)
                ));
            }
        } else {
            if (count($filesWithNonFormattingChanges) === 0) {
                return TaskResult::createFailed($this, $context, sprintf(
                    'The staged changes only differ in formatting. Please mark the commit with "%s" after the issue number for ease of auditing.',
                    self::TAG_DISPLAY_TEXT
                ));
            }

            if (count($filesWithOnlyFormattingChanges) > 0) {
                return TaskResult::createFailed($this, $context, sprintf(
                    'Formatting-only changes detected in commit not marked as formatting only. Please unstage the files with formatting-only changes:%s%s',
                    PHP_EOL,
                    implode(PHP_EOL, $filesWithOnlyFormattingChanges)
                ));
            }
        }

        return TaskResult::createPassed($this, $context);
    }
}
