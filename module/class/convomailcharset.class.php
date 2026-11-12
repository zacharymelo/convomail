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
 * \file    convomail/class/convomailcharset.class.php
 * \ingroup convomail
 * \brief   Mail text (headers, previews, bodies) as valid UTF-8
 */

/**
 * Horde_String::convertCharset() only uses mbstring, which lacks windows-1250, 1253, 1255, 1256, 1257, 1258 and 874:
 * their bytes were read as ISO-8859-1 and showed as gibberish. This class converts those with built-in tables, other
 * charsets with mbstring or iconv, and detects the charset of 8-bit text with no label or a Latin-1 label.
 */
class ConvomailCharset
{
	/** @var array<string,int[]> Bytes 0x80 to 0xFF of the Windows charsets mbstring lacks (so iconv is not required) */
	private const TABLES = array(
		'windows-1250' => array(
			0x20AC, 0xFFFD, 0x201A, 0xFFFD, 0x201E, 0x2026, 0x2020, 0x2021, 0xFFFD, 0x2030, 0x0160, 0x2039, 0x015A, 0x0164, 0x017D, 0x0179,
			0xFFFD, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0xFFFD, 0x2122, 0x0161, 0x203A, 0x015B, 0x0165, 0x017E, 0x017A,
			0x00A0, 0x02C7, 0x02D8, 0x0141, 0x00A4, 0x0104, 0x00A6, 0x00A7, 0x00A8, 0x00A9, 0x015E, 0x00AB, 0x00AC, 0x00AD, 0x00AE, 0x017B,
			0x00B0, 0x00B1, 0x02DB, 0x0142, 0x00B4, 0x00B5, 0x00B6, 0x00B7, 0x00B8, 0x0105, 0x015F, 0x00BB, 0x013D, 0x02DD, 0x013E, 0x017C,
			0x0154, 0x00C1, 0x00C2, 0x0102, 0x00C4, 0x0139, 0x0106, 0x00C7, 0x010C, 0x00C9, 0x0118, 0x00CB, 0x011A, 0x00CD, 0x00CE, 0x010E,
			0x0110, 0x0143, 0x0147, 0x00D3, 0x00D4, 0x0150, 0x00D6, 0x00D7, 0x0158, 0x016E, 0x00DA, 0x0170, 0x00DC, 0x00DD, 0x0162, 0x00DF,
			0x0155, 0x00E1, 0x00E2, 0x0103, 0x00E4, 0x013A, 0x0107, 0x00E7, 0x010D, 0x00E9, 0x0119, 0x00EB, 0x011B, 0x00ED, 0x00EE, 0x010F,
			0x0111, 0x0144, 0x0148, 0x00F3, 0x00F4, 0x0151, 0x00F6, 0x00F7, 0x0159, 0x016F, 0x00FA, 0x0171, 0x00FC, 0x00FD, 0x0163, 0x02D9,
		),
		'windows-1253' => array(
			0x20AC, 0xFFFD, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0xFFFD, 0x2030, 0xFFFD, 0x2039, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
			0xFFFD, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0xFFFD, 0x2122, 0xFFFD, 0x203A, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
			0x00A0, 0x0385, 0x0386, 0x00A3, 0x00A4, 0x00A5, 0x00A6, 0x00A7, 0x00A8, 0x00A9, 0xFFFD, 0x00AB, 0x00AC, 0x00AD, 0x00AE, 0x2015,
			0x00B0, 0x00B1, 0x00B2, 0x00B3, 0x0384, 0x00B5, 0x00B6, 0x00B7, 0x0388, 0x0389, 0x038A, 0x00BB, 0x038C, 0x00BD, 0x038E, 0x038F,
			0x0390, 0x0391, 0x0392, 0x0393, 0x0394, 0x0395, 0x0396, 0x0397, 0x0398, 0x0399, 0x039A, 0x039B, 0x039C, 0x039D, 0x039E, 0x039F,
			0x03A0, 0x03A1, 0xFFFD, 0x03A3, 0x03A4, 0x03A5, 0x03A6, 0x03A7, 0x03A8, 0x03A9, 0x03AA, 0x03AB, 0x03AC, 0x03AD, 0x03AE, 0x03AF,
			0x03B0, 0x03B1, 0x03B2, 0x03B3, 0x03B4, 0x03B5, 0x03B6, 0x03B7, 0x03B8, 0x03B9, 0x03BA, 0x03BB, 0x03BC, 0x03BD, 0x03BE, 0x03BF,
			0x03C0, 0x03C1, 0x03C2, 0x03C3, 0x03C4, 0x03C5, 0x03C6, 0x03C7, 0x03C8, 0x03C9, 0x03CA, 0x03CB, 0x03CC, 0x03CD, 0x03CE, 0xFFFD,
		),
		'windows-1255' => array(
			0x20AC, 0xFFFD, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0xFFFD, 0x2039, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
			0xFFFD, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0xFFFD, 0x203A, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
			0x00A0, 0x00A1, 0x00A2, 0x00A3, 0x20AA, 0x00A5, 0x00A6, 0x00A7, 0x00A8, 0x00A9, 0x00D7, 0x00AB, 0x00AC, 0x00AD, 0x00AE, 0x00AF,
			0x00B0, 0x00B1, 0x00B2, 0x00B3, 0x00B4, 0x00B5, 0x00B6, 0x00B7, 0x00B8, 0x00B9, 0x00F7, 0x00BB, 0x00BC, 0x00BD, 0x00BE, 0x00BF,
			0x05B0, 0x05B1, 0x05B2, 0x05B3, 0x05B4, 0x05B5, 0x05B6, 0x05B7, 0x05B8, 0x05B9, 0xFFFD, 0x05BB, 0x05BC, 0x05BD, 0x05BE, 0x05BF,
			0x05C0, 0x05C1, 0x05C2, 0x05C3, 0x05F0, 0x05F1, 0x05F2, 0x05F3, 0x05F4, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
			0x05D0, 0x05D1, 0x05D2, 0x05D3, 0x05D4, 0x05D5, 0x05D6, 0x05D7, 0x05D8, 0x05D9, 0x05DA, 0x05DB, 0x05DC, 0x05DD, 0x05DE, 0x05DF,
			0x05E0, 0x05E1, 0x05E2, 0x05E3, 0x05E4, 0x05E5, 0x05E6, 0x05E7, 0x05E8, 0x05E9, 0x05EA, 0xFFFD, 0xFFFD, 0x200E, 0x200F, 0xFFFD,
		),
		'windows-1256' => array(
			0x20AC, 0x067E, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0679, 0x2039, 0x0152, 0x0686, 0x0698, 0x0688,
			0x06AF, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x06A9, 0x2122, 0x0691, 0x203A, 0x0153, 0x200C, 0x200D, 0x06BA,
			0x00A0, 0x060C, 0x00A2, 0x00A3, 0x00A4, 0x00A5, 0x00A6, 0x00A7, 0x00A8, 0x00A9, 0x06BE, 0x00AB, 0x00AC, 0x00AD, 0x00AE, 0x00AF,
			0x00B0, 0x00B1, 0x00B2, 0x00B3, 0x00B4, 0x00B5, 0x00B6, 0x00B7, 0x00B8, 0x00B9, 0x061B, 0x00BB, 0x00BC, 0x00BD, 0x00BE, 0x061F,
			0x06C1, 0x0621, 0x0622, 0x0623, 0x0624, 0x0625, 0x0626, 0x0627, 0x0628, 0x0629, 0x062A, 0x062B, 0x062C, 0x062D, 0x062E, 0x062F,
			0x0630, 0x0631, 0x0632, 0x0633, 0x0634, 0x0635, 0x0636, 0x00D7, 0x0637, 0x0638, 0x0639, 0x063A, 0x0640, 0x0641, 0x0642, 0x0643,
			0x00E0, 0x0644, 0x00E2, 0x0645, 0x0646, 0x0647, 0x0648, 0x00E7, 0x00E8, 0x00E9, 0x00EA, 0x00EB, 0x0649, 0x064A, 0x00EE, 0x00EF,
			0x064B, 0x064C, 0x064D, 0x064E, 0x00F4, 0x064F, 0x0650, 0x00F7, 0x0651, 0x00F9, 0x0652, 0x00FB, 0x00FC, 0x200E, 0x200F, 0x06D2,
		),
		'windows-1257' => array(
			0x20AC, 0xFFFD, 0x201A, 0xFFFD, 0x201E, 0x2026, 0x2020, 0x2021, 0xFFFD, 0x2030, 0xFFFD, 0x2039, 0xFFFD, 0x00A8, 0x02C7, 0x00B8,
			0xFFFD, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0xFFFD, 0x2122, 0xFFFD, 0x203A, 0xFFFD, 0x00AF, 0x02DB, 0xFFFD,
			0x00A0, 0xFFFD, 0x00A2, 0x00A3, 0x00A4, 0xFFFD, 0x00A6, 0x00A7, 0x00D8, 0x00A9, 0x0156, 0x00AB, 0x00AC, 0x00AD, 0x00AE, 0x00C6,
			0x00B0, 0x00B1, 0x00B2, 0x00B3, 0x00B4, 0x00B5, 0x00B6, 0x00B7, 0x00F8, 0x00B9, 0x0157, 0x00BB, 0x00BC, 0x00BD, 0x00BE, 0x00E6,
			0x0104, 0x012E, 0x0100, 0x0106, 0x00C4, 0x00C5, 0x0118, 0x0112, 0x010C, 0x00C9, 0x0179, 0x0116, 0x0122, 0x0136, 0x012A, 0x013B,
			0x0160, 0x0143, 0x0145, 0x00D3, 0x014C, 0x00D5, 0x00D6, 0x00D7, 0x0172, 0x0141, 0x015A, 0x016A, 0x00DC, 0x017B, 0x017D, 0x00DF,
			0x0105, 0x012F, 0x0101, 0x0107, 0x00E4, 0x00E5, 0x0119, 0x0113, 0x010D, 0x00E9, 0x017A, 0x0117, 0x0123, 0x0137, 0x012B, 0x013C,
			0x0161, 0x0144, 0x0146, 0x00F3, 0x014D, 0x00F5, 0x00F6, 0x00F7, 0x0173, 0x0142, 0x015B, 0x016B, 0x00FC, 0x017C, 0x017E, 0x02D9,
		),
		'windows-1258' => array(
			0x20AC, 0xFFFD, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0xFFFD, 0x2039, 0x0152, 0xFFFD, 0xFFFD, 0xFFFD,
			0xFFFD, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0xFFFD, 0x203A, 0x0153, 0xFFFD, 0xFFFD, 0x0178,
			0x00A0, 0x00A1, 0x00A2, 0x00A3, 0x00A4, 0x00A5, 0x00A6, 0x00A7, 0x00A8, 0x00A9, 0x00AA, 0x00AB, 0x00AC, 0x00AD, 0x00AE, 0x00AF,
			0x00B0, 0x00B1, 0x00B2, 0x00B3, 0x00B4, 0x00B5, 0x00B6, 0x00B7, 0x00B8, 0x00B9, 0x00BA, 0x00BB, 0x00BC, 0x00BD, 0x00BE, 0x00BF,
			0x00C0, 0x00C1, 0x00C2, 0x0102, 0x00C4, 0x00C5, 0x00C6, 0x00C7, 0x00C8, 0x00C9, 0x00CA, 0x00CB, 0x0300, 0x00CD, 0x00CE, 0x00CF,
			0x0110, 0x00D1, 0x0309, 0x00D3, 0x00D4, 0x01A0, 0x00D6, 0x00D7, 0x00D8, 0x00D9, 0x00DA, 0x00DB, 0x00DC, 0x01AF, 0x0303, 0x00DF,
			0x00E0, 0x00E1, 0x00E2, 0x0103, 0x00E4, 0x00E5, 0x00E6, 0x00E7, 0x00E8, 0x00E9, 0x00EA, 0x00EB, 0x0301, 0x00ED, 0x00EE, 0x00EF,
			0x0111, 0x00F1, 0x0323, 0x00F3, 0x00F4, 0x01A1, 0x00F6, 0x00F7, 0x00F8, 0x00F9, 0x00FA, 0x00FB, 0x00FC, 0x01B0, 0x20AB, 0x00FF,
		),
		'windows-874' => array(
			0x20AC, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0x2026, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
			0xFFFD, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
			0x00A0, 0x0E01, 0x0E02, 0x0E03, 0x0E04, 0x0E05, 0x0E06, 0x0E07, 0x0E08, 0x0E09, 0x0E0A, 0x0E0B, 0x0E0C, 0x0E0D, 0x0E0E, 0x0E0F,
			0x0E10, 0x0E11, 0x0E12, 0x0E13, 0x0E14, 0x0E15, 0x0E16, 0x0E17, 0x0E18, 0x0E19, 0x0E1A, 0x0E1B, 0x0E1C, 0x0E1D, 0x0E1E, 0x0E1F,
			0x0E20, 0x0E21, 0x0E22, 0x0E23, 0x0E24, 0x0E25, 0x0E26, 0x0E27, 0x0E28, 0x0E29, 0x0E2A, 0x0E2B, 0x0E2C, 0x0E2D, 0x0E2E, 0x0E2F,
			0x0E30, 0x0E31, 0x0E32, 0x0E33, 0x0E34, 0x0E35, 0x0E36, 0x0E37, 0x0E38, 0x0E39, 0x0E3A, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD, 0x0E3F,
			0x0E40, 0x0E41, 0x0E42, 0x0E43, 0x0E44, 0x0E45, 0x0E46, 0x0E47, 0x0E48, 0x0E49, 0x0E4A, 0x0E4B, 0x0E4C, 0x0E4D, 0x0E4E, 0x0E4F,
			0x0E50, 0x0E51, 0x0E52, 0x0E53, 0x0E54, 0x0E55, 0x0E56, 0x0E57, 0x0E58, 0x0E59, 0x0E5A, 0x0E5B, 0xFFFD, 0xFFFD, 0xFFFD, 0xFFFD,
		),
	);

	/** @var array<string,string[]> Charsets tried on 8-bit text with no or a Latin-1 label => languages written in it */
	private const CANDIDATES = array(
		'windows-1252' => array('fr', 'de', 'es', 'pt', 'it', 'nl', 'nordic', 'ca'),
		'windows-1250' => array('cs', 'sk', 'pl', 'hu', 'ro', 'hr'),
		'iso-8859-2' => array('cs', 'sk', 'pl', 'hu', 'ro', 'hr'),
		'windows-1251' => array('ru', 'uk', 'bg', 'sr'),
		'koi8-r' => array('ru', 'bg'),
		'koi8-u' => array('uk'),
		'iso-8859-5' => array('ru', 'uk', 'bg', 'sr'),
		'windows-1253' => array('el'),
		'iso-8859-7' => array('el'),
		'windows-1254' => array('tr'),
		'windows-1255' => array('he'),
		'iso-8859-8' => array('he'),
		'windows-1256' => array('ar', 'fa'),
		'iso-8859-6' => array('ar'),
		'windows-1257' => array('lt', 'lv', 'et'),
		'iso-8859-13' => array('lt', 'lv', 'et'),
		'windows-1258' => array('vi'),
		'windows-874' => array('th'),
		'shift_jis' => array('ja'),
		'euc-jp' => array('ja'),
		'gb18030' => array('zh'),
		'big5' => array('zht'),
		'euc-kr' => array('ko'),
	);

	/** @var array<string,string> Language => its non-ASCII letters, most frequent first (scored by rank) */
	private const LETTERS = array(
		'fr' => 'éèàçêùâîôëïûœ',
		'de' => 'äüöéß',
		'es' => 'éóíáñúü',
		'pt' => 'ãçéáíóúêõâôà',
		'it' => 'àèéìòùó',
		'nl' => 'éëïöèü',
		'nordic' => 'äåöæøéü',
		'ca' => 'àèéíòóúçïü',
		'cs' => 'áíéěýřčšžůúťďň',
		'sk' => 'áéíýčšžúôäťďňľó',
		'pl' => 'ęąóśłżćńź',
		'hu' => 'áéőöóüíúű',
		'ro' => 'ăâîșțşţ',
		'hr' => 'čšžćđ',
		'tr' => 'ıüşöçğİâ',
		'lt' => 'ąčęėįšųūž',
		'lv' => 'āēīūšžčņļķģ',
		'et' => 'äõöüšž',
		'vi' => 'ăâêôơưđ',
		'el' => 'αοιετσνηυρπκμλςάίόέωγδήύχθφώβξζψϊΐϋΰ',
		'ru' => 'оеаинтсрвлкмдпуяыьгзбчйхжшюцщэфъё',
		'uk' => 'оанвиіетрксдлмпуяьзбгчйхжшюцщєїфґ',
		'bg' => 'оаеинтрсвлкдпмзяъбчгуйжшцфхющь',
		'sr' => 'аоиенстрјвдлкмпузгбчшћђжцњљфхџ',
		'he' => 'יוהלארמבתשנעדכקחפסגצטזךםןףץ',
		'ar' => 'اليمونربتهةعدسفكقحجشطصىخزضثذءئأإآظغ',
		'fa' => 'اینرمدوهبتسلکشزگفخپقجعحچصطغضظژثذآ',
		'th' => 'านเอรกงมยลดวสบทคตขพจะปหแใไโชฟผซศษ',
		'ja' => 'のにはをたがでてとしいるかなっすもあまうこりよらくけおんせつきやみめだどーンスルトクイリラシタア日月年分人本中出上時行事会社者方前後様申請求書支払話送願致円件確認連絡予定回新今内間下見品用',
		'zh' => '的一是不了在人有我他这个们中来上大为和国地到以说时要就出会可也你对生能而子那得于着下自之年过发后作里用道行所然家种事成方多经么去法学如都同现当没动面起看定天分还进好小部其些主样理心她本前开但因只从想实日',
		'zht' => '的一是不了在人有我他這個們中來上大為和國地到以說時要就出會可也你對生能而子那得於著下自之年過發後作裡用道行所然家種事成方多經麼去法學如都同現當沒動面起看定天分還進好小部其些主樣理心她本前開但因只從想實日',
		'ko' => '이다는의에을하고가로한지서기들리를있대수사도나인그어니으해일보시것적아자게라정만전주우제상요',
	);

	/** @var array<string,string> Company country => its usual 8-bit charset, preferred when scores tie */
	private const COUNTRIES = array(
		'GR' => 'windows-1253', 'CY' => 'windows-1253', 'RU' => 'windows-1251', 'UA' => 'windows-1251', 'BG' => 'windows-1251', 'RS' => 'windows-1251',
		'MK' => 'windows-1251', 'BY' => 'windows-1251', 'KZ' => 'windows-1251', 'PL' => 'windows-1250', 'CZ' => 'windows-1250', 'SK' => 'windows-1250',
		'HU' => 'windows-1250', 'RO' => 'windows-1250', 'HR' => 'windows-1250', 'SI' => 'windows-1250', 'BA' => 'windows-1250', 'TR' => 'windows-1254',
		'IL' => 'windows-1255', 'SA' => 'windows-1256', 'AE' => 'windows-1256', 'EG' => 'windows-1256', 'IR' => 'windows-1256', 'LT' => 'windows-1257',
		'LV' => 'windows-1257', 'EE' => 'windows-1257', 'VN' => 'windows-1258', 'TH' => 'windows-874', 'JP' => 'shift_jis', 'CN' => 'gb18030',
		'TW' => 'big5', 'HK' => 'big5', 'KR' => 'euc-kr',
	);

	/**
	 * Text as valid UTF-8, whatever the sender declared
	 *
	 * @param  string $text      Bytes
	 * @param  string $charset   Declared charset, '' when none
	 * @param  string $preferred Charset preferred when detecting (the body's, for a header), '' for none
	 * @return string            Valid UTF-8
	 */
	public static function toUtf8($text, $charset = '', $preferred = '')
	{
		$text = (string) $text;
		if ($text === '') {
			return '';
		}
		$charset = dol_strtolower(trim((string) $charset, " \t\"'"));
		// HTML with no MIME charset: its own <meta> charset
		if (in_array($charset, array('', 'us-ascii', 'ascii'), true) && preg_match('/<meta[^>]+charset=["\']?([\w.:-]+)/i', $text, $m)) {
			$charset = dol_strtolower($m[1]);
		}
		$latin = in_array($charset, array('', 'iso-8859-1', 'iso8859-1', 'latin1', 'windows-1252', 'cp1252', 'us-ascii', 'ascii'), true);

		// UTF-8 labelled Latin-1 or not labelled: valid multibyte UTF-8 is UTF-8 (a cut last character ignored)
		$trimmed = self::trimCutCharacter($text);
		if (($latin || $charset === 'utf-8' || $charset === 'utf8') && utf8_valid($trimmed)) {
			return $trimmed;
		}

		if (!$latin && $charset !== 'utf-8' && $charset !== 'utf8') {
			$converted = self::convert($text, $charset);
			if ($converted !== null) {
				return mb_scrub($converted, 'UTF-8');
			}
			dol_syslog(__METHOD__.' cannot convert from '.$charset, LOG_DEBUG);
		}

		// Mostly UTF-8 with a few bad bytes: keep it UTF-8
		$valid = preg_match_all('/[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3}/', $trimmed);
		$scrubbed = mb_scrub($trimmed, 'UTF-8');
		if ($valid > 4 * (substr_count($scrubbed, '?') - substr_count($trimmed, '?'))) {
			return $scrubbed;
		}

		// 8-bit text with no or a Latin-1 label (or a charset that cannot be converted): detect it
		// A header's raw bytes are usually in the body's charset: used when it decodes them cleanly
		$preferred = dol_strtolower(trim((string) $preferred));
		if (in_array($preferred, array('', 'utf-8', 'utf8', 'us-ascii', 'ascii', 'iso-8859-1', 'latin1', 'windows-1252', 'cp1252'), true)) {
			$preferred = ($latin && $charset !== '') ? 'windows-1252' : '';
		} else {
			$converted = self::convert($text, $preferred);
			if ($converted !== null && self::garbage(mb_scrub($converted, 'UTF-8')) === 0) {
				return mb_scrub($converted, 'UTF-8');
			}
		}
		$converted = self::convert($text, self::detect($text, $preferred));
		return mb_scrub($converted ?? $text, 'UTF-8');
	}

	/**
	 * Text a library already decoded as windows-1252 or ISO-8859-1 (its fallback for charsets mbstring lacks, raw
	 * 8-bit bytes and Latin-1 labels), decoded again from its bytes
	 *
	 * @param  string $text        UTF-8 text
	 * @param  string $bodycharset Charset of the message body, preferred when it decodes the bytes cleanly
	 * @return string              UTF-8, unchanged for Western text
	 */
	public static function redecode($text, $bodycharset = '')
	{
		$text = (string) $text;
		if (!utf8_valid($text)) {
			return self::toUtf8($text, '', $bodycharset);
		}
		if (!preg_match('/[^\x00-\x7F]/', $text)) {
			return $text;
		}
		// Only text made of ISO-8859-1 and windows-1252 characters came that way: its bytes are what was received
		$bytes = '';
		foreach (mb_str_split($text, 1, 'UTF-8') as $c) {
			$code = mb_ord($c, 'UTF-8');
			if ($code <= 0xFF) {
				$bytes .= chr($code);
			} else {
				$byte = mb_convert_encoding($c, 'Windows-1252', 'UTF-8');
				if (strlen($byte) !== 1 || $byte === '?') {
					return $text;
				}
				$bytes .= $byte;
			}
		}
		// Words from different sources (an encoded word next to raw UTF-8): each word on its own, when some words are
		// UTF-8 and others are not
		$words = preg_split('/(\s+)/', $bytes, -1, PREG_SPLIT_DELIM_CAPTURE) ?: array($bytes);
		$utf8 = $other = 0;
		foreach ($words as $word) {
			if (preg_match('/[\x80-\xFF]/', $word)) {
				utf8_valid($word) ? $utf8++ : $other++;
			}
		}
		if ($utf8 > 0 && $other > 0) {
			$out = '';
			foreach ($words as $word) {
				$out .= self::toUtf8($word, 'windows-1252', $bodycharset);
			}
			return $out;
		}
		return self::toUtf8($bytes, 'windows-1252', $bodycharset);
	}

	/**
	 * Raw header value (RFC 2047 encoded words or raw 8-bit bytes) as UTF-8
	 *
	 * @param  string $raw         Header value, unfolded or not
	 * @param  string $bodycharset Charset of the message body, preferred for raw 8-bit bytes and Latin-1 encoded words
	 * @return string              UTF-8
	 */
	public static function decodeHeader($raw, $bodycharset = '')
	{
		$raw = trim((string) preg_replace('/\r?\n[ \t]+/', ' ', (string) $raw));
		$segments = array();	// array(bytes, charset, encoded)
		$pos = 0;
		if (preg_match_all('/=\?([^?*\s]+)(?:\*[^?]*)?\?([BbQq])\?([^?\s]*)\?=/', $raw, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			foreach ($matches as $m) {
				$between = substr($raw, $pos, $m[0][1] - $pos);
				// Whitespace between two encoded words is not part of the text
				if ($between !== '' && !(trim($between) === '' && !empty($segments) && end($segments)[2])) {
					$segments[] = array($between, '', false);
				}
				$bytes = (dol_strtoupper($m[2][0]) === 'B') ? (string) base64_decode($m[3][0]) : quoted_printable_decode(str_replace('_', ' ', $m[3][0]));
				$charset = dol_strtolower($m[1][0]);
				$last = count($segments) - 1;
				if ($last >= 0 && $segments[$last][2] && $segments[$last][1] === $charset) {
					$segments[$last][0] .= $bytes;	// a multibyte character may be split over two encoded words
				} else {
					$segments[] = array($bytes, $charset, true);
				}
				$pos = $m[0][1] + strlen($m[0][0]);
			}
		}
		if ($pos < strlen($raw)) {
			$segments[] = array(substr($raw, $pos), '', false);
		}
		$out = '';
		foreach ($segments as $s) {
			$out .= self::toUtf8($s[0], $s[1], $bodycharset);
		}
		return $out;
	}

	/**
	 * Whether Horde's decoding of this raw header value can be wrong
	 *
	 * @param  string $raw Header value
	 * @return bool        True for raw 8-bit bytes or an encoded word in another charset than UTF-8
	 */
	public static function headerNeedsDecoding($raw)
	{
		$raw = (string) $raw;
		if (preg_match('/[\x80-\xFF]/', $raw)) {
			return true;
		}
		return (bool) preg_match('/=\?(?!utf-?8[?*]|us-ascii[?*])[^?\s]+\?[BbQq]\?/i', $raw);
	}

	/**
	 * Charset of 8-bit text, by decoding it in each candidate and scoring the letters that come out
	 *
	 * @param  string $text      Bytes
	 * @param  string $preferred Charset preferred when scores are close (the declared Latin-1), '' for none
	 * @return string            Lower case charset
	 */
	public static function detect($text, $preferred = '')
	{
		$sample = substr((string) $text, 0, 4000);
		if (preg_match('/\x1B\$[@B]|\x1B\(J/', $sample)) {
			return 'iso-2022-jp';
		}
		if (strpos($sample, "\x1B\$)C") !== false) {
			return 'iso-2022-kr';
		}
		// Plain text only: tags, entities and URLs are ASCII and would dilute the letters
		$sample = (string) preg_replace('/<[^>]*>|&#?\w+;|https?:\S+/', ' ', $sample);
		$hint = self::hintCharset();
		$best = 'windows-1252';
		$bestscore = null;
		foreach (self::CANDIDATES as $charset => $languages) {
			// A multibyte charset must fit the bytes (the last character may be cut)
			if (in_array($charset, array('shift_jis', 'euc-jp', 'gb18030', 'big5', 'euc-kr'), true)
				&& !mb_check_encoding($sample, $charset) && !mb_check_encoding(substr($sample, 0, -1), $charset)) {
				continue;
			}
			$decoded = self::convert($sample, $charset);
			if ($decoded === null) {
				continue;
			}
			$score = self::score($decoded, $languages, in_array($charset, array('shift_jis', 'euc-jp', 'gb18030', 'big5', 'euc-kr'), true) ? 2 : 1);
			// Close scores go to the company's usual charsets, then the declared Latin-1, then windows-1252 (listed first)
			if ($charset === $preferred) {
				$score += 0.5;
			}
			if ($hint !== '' && self::family($charset) === self::family($hint)) {
				$score += 1.0;
			}
			if ($bestscore === null || $score > $bestscore) {
				$best = $charset;
				$bestscore = $score;
			}
		}
		return $best;
	}

	/**
	 * How much decoded text looks like a language: frequent letters count, rare letters and garbage cost
	 *
	 * @param  string   $text      UTF-8 text decoded in a candidate charset
	 * @param  string[] $languages Languages written in that charset (keys of LETTERS)
	 * @param  int      $width     Bytes per non-ASCII character in that charset (1, or 2 for Chinese, Japanese, Korean)
	 * @return float               Higher is likelier
	 */
	private static function score($text, array $languages, $width)
	{
		$text = mb_scrub($text, 'UTF-8');
		$bad = self::garbage($text);
		$chars = mb_str_split((string) preg_replace('/[\x00-\x7F\s\p{P}\p{S}\p{N}\p{M}]+/u', '', dol_strtolower($text)), 1, 'UTF-8');
		if (empty($chars)) {
			return -3.0 * $bad;
		}
		$best = null;
		foreach ($languages as $language) {
			$set = mb_str_split(self::LETTERS[$language] ?? '', 1, 'UTF-8');
			$rank = array_flip($set);
			$size = max(1, count($set));
			$sum = 0.0;
			foreach ($chars as $c) {
				if (isset($rank[$c])) {
					$sum += 2.0 - 1.5 * $rank[$c] / $size;	// 2 for the most frequent letter, 0.5 for the rarest
				} elseif (($language === 'ja' && preg_match('/[\x{3041}-\x{30FA}]/u', $c)) || ($language === 'ko' && preg_match('/\p{Hangul}/u', $c))) {
					$sum += 0.5;	// other kana, other Hangul syllables
				} elseif (in_array($language, array('zh', 'zht', 'ja'), true) && preg_match('/\p{Han}/u', $c)) {
					$sum += 0.3;	// other ideographs: too many to list
				} else {
					$sum -= 1.0;
				}
			}
			$best = max($best ?? $sum, $sum);
		}
		return $width * $best - 3.0 * $bad;
	}

	/**
	 * Signs that text was decoded in the wrong charset
	 *
	 * @param  string $text UTF-8 text
	 * @return int          Count of undefined bytes and control characters, symbols and invisible format characters
	 *                      (soft hyphens anywhere, the others) stuck between letters (³ ¹ ¿ in a word), capitals after small letters, words mixing Latin with another script, marks with no
	 *                      letter before them, misplaced Thai vowels and tone marks, half-width katakana, lone Hangul
	 *                      jamo and box drawing characters (none of these appear in ordinary mail text)
	 */
	private static function garbage($text)
	{
		$nonascii = '(?=[^\x00-\x7F])';
		$bad = preg_match_all('/[\x{FFFD}\x{0080}-\x{009F}\x{00AD}\x{FF61}-\x{FF9F}\x{3131}-\x{318E}\x{2500}-\x{259F}]/u', $text);
		$bad += preg_match_all('/\p{L}[\p{S}\p{No}\p{Co}\p{Cn}\p{Cf}]\p{L}/u', $text);
		$bad += preg_match_all('/\p{Ll}'.$nonascii.'\p{Lu}/u', $text);
		$bad += preg_match_all('/[A-Za-z]'.$nonascii.'(?!\p{Latin})\p{L}|'.$nonascii.'(?!\p{Latin})\p{L}[A-Za-z]/u', $text);
		$bad += preg_match_all('/(?:^|[^\p{L}\p{M}])\p{Mn}/u', $text);
		$bad += preg_match_all('/(?<![\x{0E01}-\x{0E2E}\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}])[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]|[\x{0E40}-\x{0E44}](?![\x{0E01}-\x{0E2E}])/u', $text);
		return (int) $bad;
	}

	/**
	 * Family of a charset: its Windows and ISO variants write the same languages
	 *
	 * @param  string $charset Lower case charset
	 * @return string          The first charset in CANDIDATES that writes the same languages
	 */
	private static function family($charset)
	{
		$languages = self::CANDIDATES[$charset] ?? array();
		foreach (self::CANDIDATES as $candidate => $written) {
			if (array_intersect($written, $languages)) {
				return $candidate;
			}
		}
		return $charset;
	}

	/**
	 * Usual 8-bit charset of the company's country, only to break ties
	 *
	 * @return string Lower case charset, '' when none
	 */
	private static function hintCharset()
	{
		global $mysoc;

		$country = is_object($mysoc) ? ($mysoc->country_code ?? '') : '';
		return (is_string($country) && $country !== '') ? (self::COUNTRIES[dol_strtoupper($country)] ?? '') : '';
	}

	/**
	 * Drop the unfinished UTF-8 character a partial fetch left at the end
	 *
	 * @param  string $text Bytes
	 * @return string       Bytes
	 */
	private static function trimCutCharacter($text)
	{
		return (string) preg_replace('/(?:[\xC2-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF4][\x80-\xBF]{0,2})$/', '', $text);
	}

	/**
	 * Convert to UTF-8 with mbstring, iconv, or the built-in windows-1253 table
	 *
	 * @param  string      $text    Bytes
	 * @param  string      $charset Source charset, lower case
	 * @return string|null          UTF-8, null when the charset is unknown
	 */
	private static function convert($text, $charset)
	{
		$aliases = array('ks_c_5601-1987' => 'UHC', 'ks_c_5601-1989' => 'UHC', 'tis-620' => 'windows-874', 'iso-8859-11' => 'windows-874');
		$charset = $aliases[$charset] ?? (string) preg_replace('/^(?:x-)?cp(125\d)$/', 'windows-$1', $charset);
		if (isset(self::TABLES[$charset])) {
			return self::fromTable($text, self::TABLES[$charset]);
		}
		// Undefined bytes become U+FFFD, not '?', so detection can count them
		$substitute = mb_substitute_character();
		mb_substitute_character(0xFFFD);
		try {
			$converted = mb_convert_encoding($text, 'UTF-8', $charset);
			if (is_string($converted)) {
				return $converted;
			}
		} catch (ValueError $e) {
			// Not an mbstring charset
		} finally {
			mb_substitute_character($substitute);
		}
		if (!function_exists('iconv')) {
			return null;
		}
		// iconv warns on unknown charsets and illegal bytes: as core's email collector, silenced
		$converted = @iconv($charset, 'UTF-8//IGNORE', $text);
		return is_string($converted) ? $converted : null;
	}

	/**
	 * Single-byte text to UTF-8 with a table of its upper half
	 *
	 * @param  string $text  Bytes
	 * @param  int[]  $table Code points of bytes 0x80 to 0xFF
	 * @return string        UTF-8
	 */
	private static function fromTable($text, array $table)
	{
		$out = '';
		$length = strlen($text);	// bytes, not characters
		for ($i = 0; $i < $length; $i++) {
			$byte = ord($text[$i]);
			$out .= ($byte < 0x80) ? $text[$i] : mb_chr($table[$byte - 0x80] ?? 0xFFFD, 'UTF-8');
		}
		// windows-1258 (Vietnamese) writes tone marks as separate characters: compose them when intl is there
		return class_exists('Normalizer') ? (string) Normalizer::normalize($out, Normalizer::FORM_C) : $out;
	}
}
