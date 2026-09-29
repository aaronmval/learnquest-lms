<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

/**
 * Writes a validated slide-deck outline to a .pptx file. Layout is 16:9
 * (960 x 540 px), in one of the THEMES color schemes.
 */
class PresentationBuilderService
{
    public const DIRECTORY = 'generated-decks';

    /** Color themes the professor can pick: primary (title slide, headings) and accent. */
    public const THEMES = [
        'learnquest' => ['primary' => 'FF1E40AF', 'accent' => 'FFD97706'],
        'emerald' => ['primary' => 'FF047857', 'accent' => 'FFFBBF24'],
        'charcoal' => ['primary' => 'FF1F2937', 'accent' => 'FF0EA5E9'],
    ];

    private const INK = 'FF1F2937';

    private const WHITE = 'FFFFFFFF';

    private const FONT = 'Calibri';

    /**
     * @param  array{title: string, slides: array<int, array{title: string, bullets: array<int, string>, notes: ?string}>}  $deck
     * @return string path relative to the local disk
     */
    public function build(array $deck, string $fileId, string $theme = 'learnquest', ?string $author = null): string
    {
        $colors = self::THEMES[$theme] ?? self::THEMES['learnquest'];
        $presentation = new PhpPresentation;
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $presentation->getDocumentProperties()
            ->setTitle($deck['title'])
            ->setCreator($author ?? 'LearnQuest QuestAI');

        $this->titleSlide($presentation->getActiveSlide(), $deck['title'], $colors);

        foreach ($deck['slides'] as $index => $slide) {
            $this->contentSlide($presentation->createSlide(), $slide, $index + 1, $colors);
        }

        $relativePath = self::DIRECTORY."/{$fileId}.pptx";
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($disk->path($relativePath));

        return $relativePath;
    }

    private function titleSlide(Slide $slide, string $title, array $colors): void
    {
        $slide->setBackground((new BackgroundColor)->setColor(new Color($colors['primary'])));

        $heading = $this->textBox($slide, 60, 170, 840, 150);
        $heading->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $heading->createTextRun($title)->getFont()
            ->setName(self::FONT)->setSize(40)->setBold(true)->setColor(new Color(self::WHITE));

        $tagline = $this->textBox($slide, 60, 330, 840, 40);
        $tagline->createTextRun('Drafted with LearnQuest QuestAI Coach')->getFont()
            ->setName(self::FONT)->setSize(16)->setColor(new Color($colors['accent']));
    }

    /**
     * @param  array{title: string, bullets: array<int, string>, notes: ?string}  $content
     */
    private function contentSlide(Slide $slide, array $content, int $number, array $colors): void
    {
        $heading = $this->textBox($slide, 50, 30, 860, 70);
        $heading->createTextRun($content['title'])->getFont()
            ->setName(self::FONT)->setSize(28)->setBold(true)->setColor(new Color($colors['primary']));

        $rule = $this->textBox($slide, 50, 100, 120, 6);
        $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color($colors['accent']));

        $body = $this->textBox($slide, 50, 125, 860, 380);
        $body->setAutoFit(RichText::AUTOFIT_NORMAL);

        foreach ($content['bullets'] as $i => $bullet) {
            $paragraph = $i === 0 ? $body->getActiveParagraph() : $body->createParagraph();
            $paragraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setMarginLeft(24)->setIndent(-24);
            $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET)->setBulletChar('•');
            $paragraph->setSpacingAfter(10);
            $paragraph->createTextRun($bullet)->getFont()
                ->setName(self::FONT)->setSize(20)->setColor(new Color(self::INK));
        }

        $footer = $this->textBox($slide, 860, 505, 70, 25);
        $footer->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $footer->createTextRun((string) $number)->getFont()
            ->setName(self::FONT)->setSize(11)->setColor(new Color($colors['primary']));

        if (! empty($content['notes'])) {
            $slide->getNote()->createRichTextShape()->createTextRun($content['notes']);
        }
    }

    private function textBox(Slide $slide, int $x, int $y, int $width, int $height): RichText
    {
        return $slide->createRichTextShape()
            ->setOffsetX($x)
            ->setOffsetY($y)
            ->setWidth($width)
            ->setHeight($height);
    }
}
