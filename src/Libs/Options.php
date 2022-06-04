<?php

declare(strict_types=1);

namespace App\Libs;

final class Options
{
    public const DRY_RUN = 'DRY_RUN';
    public const FORCE_FULL = 'FORCE_FULL';
    public const DEBUG_TRACE = 'DEBUG_TRACE';
    public const IGNORE_DATE = 'IGNORE_DATE';
    public const EXPORT_ALLOWED_TIME_DIFF = 'EXPORT_TIME_DIFF';
    public const RAW_RESPONSE = 'SHOW_RAW_RESPONSE';
    public const MAPPER_ALWAYS_UPDATE_META = 'ALWAYS_UPDATE_META';
    public const MAPPER_DISABLE_AUTOCOMMIT = 'DISABLE_AUTOCOMMIT';
    public const IMPORT_METADATA_ONLY = 'IMPORT_METADATA_ONLY';

    private function __construct()
    {
    }
}
