<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * Pages controller — placeholder root that protects the home route
 * and shows a "Dashboard coming in Fase 4" landing page once the user
 * is authenticated. The full Dashboard module ships in Fase 4.
 */
class PagesController extends AppController
{
    public function home(): void
    {
        $this->set('breadcrumbs', []);
    }
}
