<?php

// Assertions indépendantes de la langue (issue #26) : clés, codes, nombres, chaînes passées à une
// fonction ou concaténées à une variable ; une seule exception justifiée (langue forcée).
final class LabelTest extends \PHPUnit\Framework\TestCase
{
    public function testKeys(): void
    {
        $this->assertSame('save', $this->action());
        $this->assertSame(42, $this->count());
        $this->assertSame(date('Y-m-d', strtotime('+15 days')), $this->deadline());
        $this->assertSame($this->name . ' Save', $this->label());
        $this->assertSame(['open', 'closed'], $this->statuses());
    }

    public function testFrenchTranslation(): void
    {
        $_SESSION['glpilanguage'] = 'fr_FR';
        $this->assertSame('Enregistrer', __('Save', 'secgood'));
    }
}
