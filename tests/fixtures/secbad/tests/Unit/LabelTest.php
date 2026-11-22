<?php

// Défauts volontaires (issue #26) : chaque assertion compare un libellé traduit et dépend donc
// de la langue (CI en anglais, instance de développement en français).
final class LabelTest extends \PHPUnit\Framework\TestCase
{
    public function testTitle(): void
    {
        $this->assertSame(sprintf(__('Item %s', 'secbad'), 'A'), $this->title());
        $this->assertStringContainsString(__('Save', 'secbad'), $this->html());
        self::assertEquals('Contact support', $this->label());
        $this->assertSame(['Registre des risques'], $this->menu());
    }
}
