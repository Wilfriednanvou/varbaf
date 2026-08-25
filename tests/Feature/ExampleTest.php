<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test de fumée hérité du squelette Laravel, corrigé plutôt que
 * supprimé.
 *
 * La racine ne sert plus la page d'accueil du framework mais celle du
 * portail public : elle interroge la base et référence les assets
 * compilés. Deux corrections en découlent.
 *
 * `withoutVite()` — sans quoi le rendu exigerait un manifeste de build,
 * absent tant que `npm run build` n'a pas tourné. Une suite de tests qui
 * dépend d'une compilation d'assets échoue sur une machine propre, et
 * pour une raison qui n'a rien à voir avec le code testé.
 *
 * `RefreshDatabase` — sans quoi le test lisait la base de développement
 * telle qu'elle se trouvait. Il passait ou non selon ce qu'on venait d'y
 * faire, ce qui est la définition d'un test qui ne prouve rien.
 *
 * La couverture réelle du portail vit dans `PortailPublicTest` ; celui-ci
 * ne vérifie qu'une chose : la racine du site répond.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_racine_sert_la_page_d_accueil_du_portail(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk();
    }
}
