<?php

namespace Holtsdev\GrumphpTasks\Task;

use GrumPHP\Collection\FilesCollection;
use GrumPHP\Runner\TaskResult;
use GrumPHP\Runner\TaskResultInterface;
use GrumPHP\Task\AbstractExternalTask;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitCommitMsgContext;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

class FormattingCheckerTask extends AbstractExternalTask
{
    const TAG_REGEX = "/[A-Z0-9-]+\s+\[Format\]/i";
    const TAG_DISPLAY_TEXT = '[Format]';
    
    const DEFAULT_FIXER_CONFIG = [
        'php_cs_fixer' => [
            'fixer_path' => 'php-cs-fixer',
            'config_path' => '.php-cs-fixer.dist.php'
        ],
        'phpcbf' => [
            'fixer_path' => 'phpcbf',
            'standard' => 'PSR12'
        ]
    ];
    
    public function getName(): string
    {
        return 'holtsdev_formatting_checker';
    }

    static public function getConfigurableOptions(): OptionsResolver
    {
        $resolver = new OptionsResolver();

        $resolver->setDefaults([
            'triggered_by' => ['php', 'phtml'],
            'fixer_config' => null
        ]);

        $resolver->addAllowedTypes('triggered_by', ['array']);
        $resolver->addAllowedTypes('fixer_config', ['array']);

        return $resolver;
    }

    public function canRunInContext(ContextInterface $context): bool
    {
        return $context instanceof GitCommitMsgContext;
    }
    
    private function resolveFixerConfigs($fixerConfigs) {
        $resolvedConfigs = [];
        
        foreach ($fixerConfigs as $name => $config) {
            if (!isset(self::DEFAULT_FIXER_CONFIG[$name])) {
                throw new \UnexpectedValueException("Invalid fixer name \"$name\".");
            }
            
            $defaultConfig = self::DEFAULT_FIXER_CONFIG[$name];
            
            foreach ($config as $key => $value) {
                if (!isset($defaultConfig[$key])) {
                    throw new \UnexpectedValueException(sprintf(
                        'Unrecognized config value "%s".',
                        $key
                    ));
                }
                
                if (gettype($defaultConfig[$key]) !== gettype($config[$key])) {
                    throw new \UnexpectedValueException(sprintf(
                        'Invalid type for config value "%s": expected %s, received %s.',
                        gettype($defaultConfig[$key]),
                        gettype($config[$key])
                    ));
                }
            }
            
            $resolvedConfigs[$name] = array_merge($defaultConfig, $config);
        }
        
        return $resolvedConfigs;
    }

    private function getNewlyAddedFiles(FilesCollection $files): array
    {
        $arguments = $this->processBuilder->createArgumentsForCommand('git');
        $arguments->add('diff');
        $arguments->add('--cached');
        $arguments->add('--name-only');
        $arguments->add('-z');
        $arguments->addRequiredArgument('--diff-filter=%s', 'A');
        $arguments->addFiles($files);
        
        $process = $this->processBuilder->buildProcess($arguments);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Failed to identify newly added files:%s%s',
                PHP_EOL,
                $process->getErrorOutput()
            ));
        }
        
        $output = $this->formatter->format($process);
        
        return $output === '' ? [] : explode("\0", $output);
    }
    
    private function getFixedHash(string $fixerName, string $filePath, array $config): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'fixer_');

        try {
            $process = Process::fromShellCommandline('git show "HEAD:$FILE_PATH" > "$TEMP_PATH"');

            $process->run(null, ['FILE_PATH' => $filePath, 'TEMP_PATH' => $tempPath]);

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(sprintf(
                    'Failed to retrieve the last committed version of the file:%s%s',
                    PHP_EOL,
                    $process->getErrorOutput()
                ));
            }

            switch ($fixerName) {
                case 'php_cs_fixer':
                    $command = '"$FIXER_PATH" fix --quiet --config "$CONFIG_PATH" "$TEMP_PATH"';

                    $params = ['FILE_PATH' => $filePath, 'FIXER_PATH' => $config['fixer_path'], 'CONFIG_PATH' => $config['config_path'], 'TEMP_PATH' => $tempPath];
                break;
                case 'phpcbf':
                    $command = '"$FIXER_PATH" -q --standard="$STANDARD" "$TEMP_PATH"';

                    $params = ['FILE_PATH' => $filePath, 'FIXER_PATH' => $config['fixer_path'], 'STANDARD' => $config['standard'], 'TEMP_PATH' => $tempPath];
                break;
                default:
                    throw new \UnexpectedValueException("Invalid fixer name \"$fixerName\".");
                break;
            }

            $process = Process::fromShellCommandline($command);

            $process->run(null, $params);

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(sprintf(
                    'Failed to run the fixer on the committed file:%s%s',
                    PHP_EOL,
                    $process->getErrorOutput() ?: $this->formatter->format($process)
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
                    'Failed to retrieved the staged version of the file:%s%s',
                    PHP_EOL,
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
        $fixerConfigs = $config['fixer_config'];
        
        if (empty($fixerConfigs)) {
            return TaskResult::createSkipped($this, $context);
        }

        $files = $context->getFiles()->extensions($config['triggered_by']);

        if (count($files) === 0) {
            return TaskResult::createSkipped($this, $context);
        }

        $matchedFormatCommitMsg = preg_match(self::TAG_REGEX, $context->getCommitMessage());

        if ($matchedFormatCommitMsg === false) {
            return TaskResult::createFailed($this, $context, sprintf(
                'Failed to check commit message:%s%s',
                PHP_EOL,
                preg_last_error_msg()
            ));
        }

        try {
            $fixerConfigs = $this->resolveFixerConfigs($fixerConfigs);
            $newFiles = $this->getNewlyAddedFiles($files);
            
            if ($matchedFormatCommitMsg && count($newFiles) > 0) {
                return TaskResult::createFailed($this, $context, sprintf(
                    'The commit is marked as formatting-only, but some staged files are new to the repository:%s%s',
                    PHP_EOL,
                    implode(PHP_EOL, $newFiles)
                ));
            }
            
            $pathNames = $files->map(function (SplFileInfo $file) {
                return $file->getPathname();
            }, $files)->toArray();
            
            $existingFiles = array_diff($pathNames, $newFiles);
            
            $filesWithNonFormattingChanges = $newFiles;
            $filesWithOnlyFormattingChanges = [];
            
            foreach ($existingFiles as $path) {
                $stagedHash = $this->getStagedHash($path);
                $fileWasFixed = false;
                
                foreach($fixerConfigs as $fixerName => $fixerConfig){
                    $fixedHash = $this->getFixedHash($fixerName, $path, $fixerConfig);
                    
                    if ($stagedHash === $fixedHash) {
                        $fileWasFixed = true;
                        break;
                    }
                }

                if ($fileWasFixed) {
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
