<?php

namespace App\Console\Commands;

use App\MasterData\Import\MasterDataImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('pss:import-master-data')]
#[Description('Copy Sites, Equipment, Equipment Categories and Employees from the source database into PSS (read-only)')]
class ImportMasterData extends Command
{
    public const string CONNECTION = 'master_source';

    public const string NOT_CONFIGURED_MESSAGE = 'The master data source database is not configured. Set MASTER_SOURCE_DB_DATABASE (and the other MASTER_SOURCE_DB_* variables) in .env. Nothing was imported.';

    /**
     * Execute the console command. Runs nightly (routes/console.php) and by hand. Safe to run again.
     */
    public function handle(MasterDataImporter $importer): int
    {
        if (trim((string) config('database.connections.'.self::CONNECTION.'.database')) === '') {
            $this->error(self::NOT_CONFIGURED_MESSAGE);

            return self::FAILURE;
        }

        try {
            $summary = $importer->import(DB::connection(self::CONNECTION));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The master data import failed and changed nothing: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Table', 'Inserted', 'Updated', 'Deactivated'],
            collect($summary)->map(fn (array $counts, string $table): array => [
                $table, $counts['inserted'], $counts['updated'], $counts['deactivated'],
            ])->values()->all(),
        );
        $this->info('Master data import finished.');

        return self::SUCCESS;
    }
}
