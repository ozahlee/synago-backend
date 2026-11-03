<?php

namespace App\Support;

/**
 * Reads Bible references the way people type them ("Jn 15 1-8", "1cor 13:4-7", "ps 23", "Gen.1.1")
 * and writes them the standard way ("John 15:1–8"). One reference per call; split lists on ";" first.
 */
final class Scripture
{
    /** Canonical book names in order (Protestant 66), with common abbreviations / local spellings. */
    public const BOOKS = [
        'Genesis' => ['gen', 'ge', 'gn'], 'Exodus' => ['exod', 'exo', 'ex'], 'Leviticus' => ['lev', 'le', 'lv'],
        'Numbers' => ['num', 'nu', 'nm', 'numb'], 'Deuteronomy' => ['deut', 'deu', 'dt'], 'Joshua' => ['josh', 'jos'],
        'Judges' => ['judg', 'jdg', 'jg'], 'Ruth' => ['ru', 'rth'], '1 Samuel' => ['1 sam', '1sam', '1 sa', '1sa', 'i samuel'],
        '2 Samuel' => ['2 sam', '2sam', '2 sa', '2sa', 'ii samuel'], '1 Kings' => ['1 kgs', '1kgs', '1 ki', '1ki', 'i kings'],
        '2 Kings' => ['2 kgs', '2kgs', '2 ki', '2ki', 'ii kings'], '1 Chronicles' => ['1 chr', '1chr', '1 ch', '1ch', '1 chron'],
        '2 Chronicles' => ['2 chr', '2chr', '2 ch', '2ch', '2 chron'], 'Ezra' => ['ezr'], 'Nehemiah' => ['neh', 'ne'],
        'Esther' => ['esth', 'est', 'es'], 'Job' => ['jb'], 'Psalms' => ['psalm', 'ps', 'psa', 'pss', 'psm'],
        'Proverbs' => ['prov', 'pro', 'prv', 'pr'], 'Ecclesiastes' => ['eccl', 'ecc', 'ec', 'qoh'],
        'Song of Solomon' => ['song', 'sos', 'song of songs', 'songs', 'sg', 'cant'], 'Isaiah' => ['isa', 'is'],
        'Jeremiah' => ['jer', 'je', 'jr'], 'Lamentations' => ['lam', 'la'], 'Ezekiel' => ['ezek', 'eze', 'ezk'],
        'Daniel' => ['dan', 'da', 'dn'], 'Hosea' => ['hos', 'ho'], 'Joel' => ['jl'], 'Amos' => ['am'], 'Obadiah' => ['obad', 'ob'],
        'Jonah' => ['jon', 'jnh'], 'Micah' => ['mic', 'mi'], 'Nahum' => ['nah', 'na'], 'Habakkuk' => ['hab', 'hb'],
        'Zephaniah' => ['zeph', 'zep', 'zp'], 'Haggai' => ['hag', 'hg'], 'Zechariah' => ['zech', 'zec', 'zc'], 'Malachi' => ['mal', 'ml'],
        'Matthew' => ['matt', 'mat', 'mt'], 'Mark' => ['mrk', 'mar', 'mk', 'mr'], 'Luke' => ['luk', 'lk'], 'John' => ['joh', 'jhn', 'jn'],
        'Acts' => ['act', 'ac'], 'Romans' => ['rom', 'ro', 'rm'], '1 Corinthians' => ['1 cor', '1cor', '1 co', '1co', 'i corinthians'],
        '2 Corinthians' => ['2 cor', '2cor', '2 co', '2co', 'ii corinthians'], 'Galatians' => ['gal', 'ga'],
        'Ephesians' => ['eph', 'ephes'], 'Philippians' => ['phil', 'php', 'pp'], 'Colossians' => ['col', 'co'],
        '1 Thessalonians' => ['1 thess', '1thess', '1 th', '1th', 'i thessalonians'], '2 Thessalonians' => ['2 thess', '2thess', '2 th', '2th', 'ii thessalonians'],
        '1 Timothy' => ['1 tim', '1tim', '1 ti', '1ti', 'i timothy'], '2 Timothy' => ['2 tim', '2tim', '2 ti', '2ti', 'ii timothy'],
        'Titus' => ['tit', 'ti'], 'Philemon' => ['philem', 'phm', 'pm'], 'Hebrews' => ['heb'], 'James' => ['jas', 'jm', 'jam'],
        '1 Peter' => ['1 pet', '1pet', '1 pe', '1pe', '1 pt', '1pt', 'i peter'], '2 Peter' => ['2 pet', '2pet', '2 pe', '2pe', '2 pt', '2pt', 'ii peter'],
        '1 John' => ['1 jn', '1jn', '1 jhn', '1john', 'i john'], '2 John' => ['2 jn', '2jn', '2john', 'ii john'], '3 John' => ['3 jn', '3jn', '3john', 'iii john'],
        'Jude' => ['jud', 'jd'], 'Revelation' => ['rev', 're', 'rv', 'revelations'],
    ];

    /** Books with a single chapter: "Jude 3" means verse 3. */
    private const ONE_CHAPTER = ['Obadiah', 'Philemon', '2 John', '3 John', 'Jude'];

    /** @return array{ref: string, book: string, bookIndex: int, testament: string}|null */
    public static function parse(string $raw): ?array
    {
        $s = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['–', '—', '.'], ['-', '-', ' '], $raw))));
        if ($s === '') {
            return null;
        }
        // split "1cor13:4" style: book part = leading number + letters (with spaces), rest = numbers
        if (! preg_match('/^((?:[1-3]|i{1,3})?\s*[a-z][a-z ]*?)\s*(\d.*)?$/', $s, $m)) {
            return null;
        }
        $bookKey = trim(preg_replace('/^(i{1,3})\s+/', '$1 ', $m[1]));
        $bookKey = preg_replace('/^([1-3])\s*/', '$1 ', $bookKey);
        $book = self::book($bookKey);
        if (! $book) {
            return null;
        }
        $rest = trim($m[2] ?? '');
        $index = array_search($book, array_keys(self::BOOKS), true);
        $base = ['book' => $book, 'bookIndex' => $index, 'testament' => $index < 39 ? 'OT' : 'NT'];
        if ($rest === '') {
            return ['ref' => $book] + $base;
        }
        // normalise separators: "15 1-8", "15:1-8", "15 v1-8", "15:1-16:4"
        $rest = preg_replace(['/\s*-\s*/', '/\s*:\s*/', '/\bv(v)?\s*/', '/\s*,\s*/'], ['-', ':', '', ','], $rest);
        $rest = preg_replace('/^(\d+) (\d)/', '$1:$2', $rest);
        if (! preg_match('/^\d+(:\d+([a-c])?(,\d+)*)?(-\d+(:\d+)?)?$/', $rest)) {
            return null;
        }
        if (in_array($book, self::ONE_CHAPTER, true) && ! str_contains($rest, ':')) {
            $rest = "1:{$rest}";
        }

        $label = $book === 'Psalms' && ! preg_match('/^\d+-\d+$/', $rest) ? 'Psalm' : $book; // "Psalm 23", "Psalms 23–24"

        return ['ref' => $label.' '.str_replace([',', '-'], [', ', '–'], $rest)] + $base;
    }

    private static function book(string $key): ?string
    {
        foreach (self::BOOKS as $name => $aliases) {
            if ($key === strtolower($name) || in_array($key, $aliases, true)) {
                return $name;
            }
        }
        // unambiguous prefix of a full name ("phili" → Philippians, but "jo" is ambiguous)
        $hits = array_values(array_filter(array_keys(self::BOOKS), fn ($n) => strlen($key) >= 3 && str_starts_with(strtolower($n), $key)));

        return count($hits) === 1 ? $hits[0] : null;
    }
}
