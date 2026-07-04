<?php
/**
 * Cast text building: the 1024-BYTE limit (bytes, not characters —
 * measured with strlen on UTF-8), word-boundary truncation, permalink
 * appending, and multibyte safety.
 */

require __DIR__ . '/wp-stubs.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-publisher.php';

$permalink = 'https://example.com/note/42/';
$limit = BSync_FC_Publisher::CAST_BYTE_LIMIT;

/* ---- short note: sent as-is ---- */

$short = 'A short note that easily fits.';
$built = BSync_FC_Publisher::build_cast_text($short, $permalink, false);
check('short note untouched', $built['text'] === $short);
check('short note not marked truncated', $built['truncated'] === false);

/* ---- short note with always-append ---- */

$built = BSync_FC_Publisher::build_cast_text($short, $permalink, true);
check('always-append adds the permalink', strpos($built['text'], $permalink) !== false);
check('always-append keeps full text', strpos($built['text'], $short) === 0);
check('always-append fits the limit', strlen($built['text']) <= $limit);

/* ---- acceptance criterion 8: a 2000-byte note ---- */

$words = array();
$i = 0;
while (strlen(implode(' ', $words)) < 2000) {
    $words[] = 'word' . ($i++);
}
$long = implode(' ', $words);
check('fixture is ~2000 bytes', strlen($long) >= 2000);

$built = BSync_FC_Publisher::build_cast_text($long, $permalink, false);
check('2000-byte note is marked truncated', $built['truncated'] === true);
check('2000-byte note fits within 1024 BYTES', strlen($built['text']) <= $limit);
check('truncated note ends with the permalink', substr($built['text'], -strlen($permalink)) === $permalink);
check('truncated note contains an ellipsis', strpos($built['text'], '…') !== false);

// Word boundary: the text before the ellipsis must end exactly at the
// end of one of the fixture words (no mid-word cut).
$ellipsis_pos = strpos($built['text'], '…');
$kept = substr($built['text'], 0, $ellipsis_pos);
$last_word = ($p = strrpos($kept, ' ')) !== false ? substr($kept, $p + 1) : $kept;
check('truncation lands on a word boundary', in_array($last_word, $words, true));

/* ---- multibyte: never split a UTF-8 character ---- */

// 4-byte emoji repeated: 300 x 4 = 1200 bytes with no spaces.
$emoji = str_repeat("\xF0\x9F\x8D\x89", 300);
$built = BSync_FC_Publisher::build_cast_text($emoji, $permalink, false);
check('emoji wall fits limit', strlen($built['text']) <= $limit);
check('emoji wall remains valid UTF-8', mb_check_encoding($built['text'], 'UTF-8'));

// Multibyte words: truncation must respect boundaries and stay valid.
$mb_text = trim(str_repeat('日本語のテキスト ', 120)); // ~2.6KB
$built = BSync_FC_Publisher::build_cast_text($mb_text, $permalink, false);
check('multibyte text fits limit in bytes', strlen($built['text']) <= $limit);
check('multibyte text remains valid UTF-8', mb_check_encoding($built['text'], 'UTF-8'));

/* ---- exactly at the limit ---- */

$exact = str_repeat('a', $limit);
$built = BSync_FC_Publisher::build_cast_text($exact, $permalink, false);
check('exactly-1024-byte note untouched', $built['text'] === $exact && $built['truncated'] === false);

$over = str_repeat('a', $limit) . ' overflow';
$built = BSync_FC_Publisher::build_cast_text($over, $permalink, false);
check('1024+ note truncated and within limit', $built['truncated'] === true && strlen($built['text']) <= $limit);

/* ---- HTML is flattened, entities decoded, breaks preserved ---- */

$html = "<p>First paragraph.</p><p>Second &amp; third.</p>";
$plain = BSync_FC_Publisher::plain_text($html);
check('tags stripped', strpos($plain, '<p>') === false);
check('entities decoded', strpos($plain, '&amp;') === false && strpos($plain, '&') !== false);
check('paragraph break preserved', strpos($plain, "First paragraph.\n") === 0);

finish('TRUNCATION');
