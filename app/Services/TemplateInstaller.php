<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

class TemplateInstaller
{
    public function __construct(
        private TemplateRegistry $registry,
        private ConfigLoader $configLoader,
    ) {}

    /**
     * Install a template rule into a project's dispatch.yml.
     *
     * @return array{success: bool, message: string}
     */
    public function install(string $templateId, Project $project): array
    {
        $template = $this->registry->find($templateId);

        if (! $template) {
            return ['success' => false, 'message' => 'Template not found.'];
        }

        $filePath = rtrim($project->path, '/').'/dispatch.yml';

        if (! file_exists($filePath)) {
            return ['success' => false, 'message' => 'No dispatch.yml found in project.'];
        }

        try {
            $data = Yaml::parseFile($filePath);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to parse dispatch.yml: '.$e->getMessage()];
        }

        if (! is_array($data) || ! isset($data['rules']) || ! is_array($data['rules'])) {
            return ['success' => false, 'message' => 'Invalid dispatch.yml structure.'];
        }

        // Check if a rule with this ID already exists
        foreach ($data['rules'] as $rule) {
            if (is_array($rule) && ($rule['id'] ?? null) === $template['rule']['id']) {
                return ['success' => false, 'message' => "Rule \"{$template['rule']['id']}\" already exists in dispatch.yml."];
            }
        }

        $data['rules'][] = $template['rule'];

        $yaml = Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        if (file_put_contents($filePath, "---\n".$yaml) === false) {
            return ['success' => false, 'message' => 'Failed to write dispatch.yml.'];
        }

        // Clear config cache so the new rule is picked up
        $this->configLoader->clearCache($project->path);

        Log::info("Template \"{$templateId}\" installed to project \"{$project->repo}\"", [
            'rule_id' => $template['rule']['id'],
            'project_id' => $project->id,
        ]);

        return ['success' => true, 'message' => "Template \"{$template['name']}\" installed."];
    }

    /**
     * Check which templates are already installed for a project.
     *
     * @return list<string> List of installed template rule IDs
     */
    public function installedRuleIds(Project $project): array
    {
        $filePath = rtrim($project->path, '/').'/dispatch.yml';

        if (! file_exists($filePath)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($filePath);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($data) || ! isset($data['rules']) || ! is_array($data['rules'])) {
            return [];
        }

        $ids = [];
        foreach ($data['rules'] as $rule) {
            if (is_array($rule) && isset($rule['id'])) {
                $ids[] = $rule['id'];
            }
        }

        return $ids;
    }
}
