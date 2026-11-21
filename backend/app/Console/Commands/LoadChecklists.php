<?php

namespace App\Console\Commands;

use App\Checklists\ChecklistDefinitions;
use App\Checklists\ChecklistLoader;
use App\Checklists\InvalidChecklistDefinition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('checklists:load')]
#[Description('Load the fixed PM Checklist definitions, creating a new version only where a definition changed')]
class LoadChecklists extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ChecklistDefinitions $definitions, ChecklistLoader $loader): int
    {
        try {
            $results = $loader->load($definitions->all());
        } catch (InvalidChecklistDefinition $exception) {
            $this->error($exception->getMessage());
            $this->error('Nothing was loaded.');

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $this->line(sprintf(
                '%s: version %d %s',
                $result['key'],
                $result['version_number'],
                $result['created'] ? 'created' : 'unchanged',
            ));
        }

        $this->info(count($results).' checklist definition(s) processed.');

        return self::SUCCESS;
    }
}
