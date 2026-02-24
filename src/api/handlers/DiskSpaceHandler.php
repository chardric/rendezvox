<?php

declare(strict_types=1);

class DiskSpaceHandler
{
    public function handle(): void
    {
        Response::json(DiskSpace::check());
    }
}
