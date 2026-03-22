<?php

namespace App\Ai\Contracts;

use App\Models\Project;

interface ProjectAware
{
    public function setProject(Project $project): void;
}
