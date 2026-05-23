<?php

namespace App\Console\Commands;

use App\Models\Theme;
use Illuminate\Console\Command;

class SeedThemes extends Command
{
    protected $signature = 'market:seed-themes';

    protected $description = 'Seed initial theme definitions from the product plan.';

    public function handle(): int
    {
        $themes = [
            ['name' => 'AI Server', 'slug' => 'ai-server', 'description' => 'AI server supply chain and related capital expenditure beneficiaries.'],
            ['name' => 'Thermal', 'slug' => 'thermal', 'description' => 'Cooling, heat dissipation, liquid cooling, fans, and thermal modules.'],
            ['name' => 'CoWoS', 'slug' => 'cowos', 'description' => 'Advanced packaging, CoWoS capacity, substrate, testing, and related equipment.'],
            ['name' => 'Optical Communication', 'slug' => 'optical-communication', 'description' => 'Optical modules, silicon photonics, high-speed transmission, and data center networking.'],
        ];

        foreach ($themes as $theme) {
            Theme::query()->updateOrCreate(
                ['slug' => $theme['slug']],
                $theme + ['is_active' => true],
            );
        }

        $this->info('Themes seeded: '.count($themes));

        return self::SUCCESS;
    }
}
