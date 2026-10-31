<?php

namespace BradTipper\AppBackend\OpenApi;

use BradTipper\AppBackend\Api\ApiController;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `sake tasks:openapi-export --output=openapi.json` writes the API contract.
 */
class OpenApiExportTask extends BuildTask
{
    protected static string $commandName = 'openapi-export';

    protected string $title = 'Export the OpenAPI document';

    protected static string $description = 'Writes openapi.json from the describe() methods on every ApiController.';

    public function getOptions(): array
    {
        return [
            new InputOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write (default: stdout)'),
            new InputOption('title', null, InputOption::VALUE_REQUIRED, 'API title', 'App API'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $json = self::build((string) $input->getOption('title'))->toJson();

        $file = $input->getOption('output');
        if ($file) {
            file_put_contents($file, $json);
            $output->writeln("Wrote {$file}");
        } else {
            $output->writeln($json);
        }
        return Command::SUCCESS;
    }

    public static function build(string $title = 'App API'): Spec
    {
        $server = (string) (Environment::getEnv('API_PUBLIC_URL') ?: 'http://localhost');
        $spec = new Spec($title, '1.0.0', $server);
        CommonSchemas::register($spec);

        $classes = ClassInfo::subclassesFor(ApiController::class, false);
        sort($classes);
        foreach ($classes as $class) {
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            if (method_exists($class, 'describe')) {
                $class::describe($spec);
            }
        }
        return $spec;
    }
}
