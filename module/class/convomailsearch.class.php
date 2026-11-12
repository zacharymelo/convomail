<?php
/* Copyright (C) 2026 Nick Fragoulis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    convomail/class/convomailsearch.class.php
 * \ingroup convomail
 * \brief   Search text of the mail list: free words plus filters from:, subject:, since: and until:
 */

/**
 * The search text carries the filters (from:maria subject:'common expenses' since:2026-11-01), so they
 * follow the search everywhere it already goes: paging, page size, the Combined Inbox, the result cache.
 * Multi-word values are quoted with single quotes: GETPOST() removes double quotes.
 */
class ConvomailSearch
{
	/** Filter keys, in the order they are written */
	const KEYS = array('from', 'subject', 'since', 'until');

	/**
	 * Split a search text into its free words and its filters
	 *
	 * @param  string $search Search text
	 * @return array{words:string,from:string,subject:string,since:string,until:string}
	 */
	public static function parse($search)
	{
		$out = array('words' => '', 'from' => '', 'subject' => '', 'since' => '', 'until' => '');
		$words = array();
		$search = (string) $search;
		if (preg_match_all('/(?:([a-z]+):)?(?:\'([^\']*)\'(?=\s|$)|"([^"]*)"|(\S+))/u', $search, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$key = dol_strtolower($m[1] ?? '');
				$value = trim(($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')));
				if ($key !== '' && in_array($key, self::KEYS, true)) {
					if (($key === 'since' || $key === 'until') && !self::isDate($value)) {
						continue;
					}
					if ($value !== '') {
						$out[$key] = $value;
					}
				} elseif ($m[0] !== '') {
					// An unknown "word:value" stays a word, as typed
					$word = trim($m[0], '\'"');
					if ($word !== '') {
						$words[] = $word;
					}
				}
			}
		}
		$out['words'] = trim(implode(' ', $words));
		return $out;
	}

	/**
	 * Search text from free words and filters; invalid dates are left out
	 *
	 * @param  string $words   Free words
	 * @param  string $from    Sender (name or address part)
	 * @param  string $subject Subject part
	 * @param  string $since   First day, YYYY-MM-DD
	 * @param  string $until   Last day, YYYY-MM-DD
	 * @return string
	 */
	public static function compose($words, $from = '', $subject = '', $since = '', $until = '')
	{
		$parts = array();
		$words = trim((string) $words);
		if ($words !== '') {
			$parts[] = $words;
		}
		foreach (array('from' => $from, 'subject' => $subject) as $key => $value) {
			$value = trim((string) preg_replace('/\s+/u', ' ', str_replace('"', ' ', (string) $value)));
			if (strpos($value, ' ') !== false) {
				// Quoted with single quotes, so one inside can't stay ("O'Brien Ltd" becomes "O Brien Ltd")
				$value = '\''.trim((string) preg_replace('/\s+/u', ' ', str_replace('\'', ' ', $value))).'\'';
			}
			if ($value !== '' && $value !== '\'\'') {
				$parts[] = $key.':'.$value;
			}
		}
		foreach (array('since' => $since, 'until' => $until) as $key => $value) {
			if (self::isDate((string) $value)) {
				$parts[] = $key.':'.$value;
			}
		}
		return implode(' ', $parts);
	}

	/**
	 * Search text of the request: the search box, with the filter fields of the Filter panel when it was sent
	 * (fsender, fsubject, fsince, funtil). Links carry the composed text in search alone.
	 *
	 * @return string
	 */
	public static function fromRequest()
	{
		$search = trim(GETPOST('search', 'alphanohtml'));
		if (!GETPOSTISSET('fsender') && !GETPOSTISSET('fsubject') && !GETPOSTISSET('fsince') && !GETPOSTISSET('funtil')) {
			return $search;
		}
		// The box shows the free words; filters typed in it are kept unless the panel sets them
		$typed = self::parse($search);
		return self::compose($typed['words'], self::panelValue('fsender', $typed['from']), self::panelValue('fsubject', $typed['subject']),
			self::panelValue('fsince', $typed['since']), self::panelValue('funtil', $typed['until']));
	}

	/**
	 * Value of a Filter panel field, else the same filter typed in the search box
	 *
	 * @param  string $name  Field name
	 * @param  string $typed Value typed in the search box
	 * @return string
	 */
	private static function panelValue($name, $typed)
	{
		$value = trim(GETPOST($name, 'alphanohtml'));
		return $value !== '' ? $value : $typed;
	}

	/**
	 * Whether a value is a real day written YYYY-MM-DD
	 *
	 * @param  string $value Value
	 * @return bool
	 */
	public static function isDate($value)
	{
		return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
	}

	/**
	 * Filter panel of the list toolbar: a Filter button opening sender, subject and date fields,
	 * inside the search form (its free words stay in the search box)
	 *
	 * @param  array{words:string,from:string,subject:string,since:string,until:string} $parsed  Current search, parse()
	 * @param  string                                                                     $clearurl List without the search
	 * @return string HTML
	 */
	public static function panelHtml(array $parsed, $clearurl)
	{
		global $langs;
		/** @var Translate $langs */

		$active = ($parsed['from'] !== '' || $parsed['subject'] !== '' || $parsed['since'] !== '' || $parsed['until'] !== '');
		$h = '<details class="cm-filter'.($active ? ' cm-filter-active' : '').'">';
		$h .= '<summary class="cm-btn cm-iconbtn" title="'.dol_escape_htmltag($langs->trans('ConvomailFilter')).'">'.img_picto('', 'fa-filter').'</summary>';
		$h .= '<div class="cm-filter-panel">';
		$h .= '<label>'.$langs->trans('ConvomailFilterSender').'<input type="text" name="fsender" value="'.dol_escape_htmltag($parsed['from']).'" placeholder="'.dol_escape_htmltag($langs->trans('ConvomailFilterSenderHelp')).'"></label>';
		$h .= '<label>'.$langs->trans('ConvomailFilterSubject').'<input type="text" name="fsubject" value="'.dol_escape_htmltag($parsed['subject']).'"></label>';
		$h .= '<label>'.$langs->trans('ConvomailFilterSince').'<input type="date" name="fsince" value="'.dol_escape_htmltag($parsed['since']).'"></label>';
		$h .= '<label>'.$langs->trans('ConvomailFilterUntil').'<input type="date" name="funtil" value="'.dol_escape_htmltag($parsed['until']).'"></label>';
		$h .= '<div class="cm-filter-buttons">';
		$h .= '<button type="submit" class="button small">'.$langs->trans('ConvomailFilterApply').'</button>';
		if ($active || $parsed['words'] !== '') {
			$h .= ' <a class="button button-cancel small" href="'.dol_escape_htmltag($clearurl).'">'.$langs->trans('ConvomailFilterClear').'</a>';
		}
		$h .= '</div></div></details>';
		return $h;
	}
}
