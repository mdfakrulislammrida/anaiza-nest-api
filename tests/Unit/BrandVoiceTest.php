<?php

namespace Tests\Unit;

use App\Support\BrandVoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrandVoiceTest extends TestCase
{
    public function test_text_in_the_kit_voice_raises_no_notes(): void
    {
        $this->assertSame([], BrandVoice::notes('Packed by hand, sent with care. ৳1,450, gift box included. 🎁'));
        $this->assertSame([], BrandVoice::notes('যত্নে সাজানো উপহার।'));
        $this->assertSame([], BrandVoice::notes(''));
        $this->assertSame([], BrandVoice::notes(null));
        $this->assertSame([], BrandVoice::notes('   '));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bannedPhrases(): array
    {
        return [
            'best quality' => ['Best quality tea set', 'best quality'],
            'luxury premium' => ['A luxury premium gift', 'luxury premium'],
            'exclusive' => ['An EXCLUSIVE offer', 'exclusive'],
            'elite' => ['For the elite', 'elite'],
            'cheap' => ['Not cheap, not dear', 'cheap'],
            'hurry' => ['Hurry while it lasts', 'hurry'],
            'grab now' => ['Grab now and save', 'grab now'],
            'limited stock' => ['Limited stock left', 'limited stock'],
            'world class' => ['A world class finish', 'world class'],
            'world-class' => ['A world-class finish', 'world-class'],
            'amazing' => ['Amazing glaze', 'amazing'],
            'must-have' => ['A must-have set', 'must-have'],
            'must have' => ['You must have this', 'must have'],
            'hot deal' => ['A hot deal', 'hot deal'],
            'hot deals plural' => ['Hot deals this week', 'hot deal'],
            'wow' => ['Wow, what a cup', 'wow'],
        ];
    }

    #[DataProvider('bannedPhrases')]
    public function test_it_flags_every_word_the_kit_leaves_out(string $text, string $phrase): void
    {
        $notes = BrandVoice::notes($text);

        $this->assertNotEmpty($notes, $text);
        $this->assertStringContainsString("\"{$phrase}\"", implode(' ', $notes));
    }

    public function test_it_does_not_flag_a_banned_word_inside_a_longer_word(): void
    {
        $this->assertSame([], BrandVoice::notes('A slower, elitely quiet afternoon. Showcase the cheapest-looking? No. Wowser.'), 'only whole words count');
        $this->assertSame([], BrandVoice::notes('Cheapside and Eliteness'));
    }

    public function test_it_flags_shouting_but_not_the_allow_list_or_short_words(): void
    {
        $this->assertSame([], BrandVoice::notes('Pay by COD, or SMS us. Made in the UK. USB lamp. BDT prices. FAQ and PDF.'));
        $this->assertSame([], BrandVoice::notes('A4 box, ID card, TV set, 12 CM'), 'one and two letter words are ignored');

        $notes = BrandVoice::notes('A PREMIUM set, ONLY today, via COD');
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('"PREMIUM"', $notes[0]);
        $this->assertStringContainsString('"ONLY"', $notes[1]);
    }

    public function test_it_flags_exclamation_marks_and_counts_them(): void
    {
        $this->assertSame(['There is an exclamation mark. The kit does not shout.'], BrandVoice::notes('Lovely!'));
        $this->assertSame(['There are 3 exclamation marks. The kit does not shout.'], BrandVoice::notes('Lovely!!!'));
        $this->assertSame([], BrandVoice::notes('Lovely.'));
    }

    public function test_up_to_three_kit_emoji_are_fine(): void
    {
        $this->assertSame([], BrandVoice::notes('A gift for her 🎁 ✨ 🤍'));
        $this->assertSame([], BrandVoice::notes('🫖 🚚'));
    }

    public function test_more_than_three_emoji_is_flagged_even_if_they_are_all_kit_emoji(): void
    {
        $notes = BrandVoice::notes('🎁 ✨ 🤍 🚚');

        $this->assertSame(['4 emoji. The kit allows at most 3.'], $notes);
    }

    public function test_emoji_outside_the_kit_set_are_named(): void
    {
        $notes = BrandVoice::notes('So lovely 😀 and 🔥');

        $this->assertCount(1, $notes);
        $this->assertStringContainsString('Emoji outside the kit set', $notes[0]);
        [, $named] = explode('): ', $notes[0]);
        $this->assertStringContainsString('😀', $named);
        $this->assertStringContainsString('🔥', $named);
        $this->assertStringNotContainsString('🎁', $named, 'only the emoji that broke the rule are named');
    }

    public function test_the_taka_sign_and_ordinary_symbols_are_not_emoji(): void
    {
        $this->assertSame([], BrandVoice::notes('৳1,450 → 2 × 3 – 4 © ™ ½'));
    }

    public function test_html_is_read_as_text_and_tags_do_not_glue_words_together(): void
    {
        $this->assertSame([], BrandVoice::notes('<p>Packed by hand.</p><p>Sent with care.</p>'));
        $this->assertNotEmpty(BrandVoice::notes('<h2>Amazing</h2><p>glaze</p>'));
        $this->assertNotEmpty(BrandVoice::notes('<p>Don&#039;t HURRY!</p>'));
    }

    public function test_the_same_text_can_raise_several_kinds_of_note(): void
    {
        $notes = BrandVoice::notes('AMAZING exclusive deal!!! 😀');

        $this->assertCount(5, $notes, implode(' | ', $notes));
    }

    public function test_the_summary_counts_notes_across_fields_and_stays_silent_when_clean(): void
    {
        $this->assertNull(BrandVoice::summary(['A calm name', 'A calm line.', null]));
        $this->assertSame('Brand check: 1 note', BrandVoice::summary(['Amazing set', 'Fine.']));
        $this->assertSame('Brand check: 3 notes', BrandVoice::summary(['Amazing set', 'Hurry!']), 'amazing, hurry and the exclamation mark');
        $this->assertSame(3, BrandVoice::count(['Amazing', 'Wow!', null]));
    }
}
