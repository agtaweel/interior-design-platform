/**
 * Per-block Arabic direction detection, independent of `LocaleProvider`'s app-chrome locale
 * toggle. The S11 public proposal portal keeps its UI chrome (labels/buttons) in one language,
 * but the actual proposal content (cover note, scope, terms, client name...) is free-text
 * authored by staff and is very often Arabic (see docs/PROJECT_CONTEXT.md: "make sure Arabic
 * client names/content render correctly with correct text direction even if you keep the UI
 * chrome itself in one language"). Rather than making the whole page RTL/LTR based on a single
 * toggle, each content block picks its own `dir` based on whether it actually contains Arabic
 * script — a proposal with an Arabic cover note and English BOQ item descriptions renders both
 * correctly at once.
 *
 * Uses `\u{...}` escapes (never pasted literal glyphs) so the exact code points are unambiguous
 * and no invisible/control character can sneak into the source file.
 */

// Arabic U+0600-06FF, Arabic Supplement U+0750-077F, Arabic Extended-A U+08A0-08FF,
// Arabic Presentation Forms-A U+FB50-FDFF, Arabic Presentation Forms-B U+FE70-FEFC
// (stops short of U+FEFF, the BOM/zero-width-no-break-space, which is not a visible glyph).
const ARABIC_SCRIPT_RANGE = new RegExp(
  "[؀-ۿݐ-ݿࢠ-ࣿﭐ-﷿ﹰ-ﻼ]",
);

/** True if `text` contains any Arabic-script character. Empty/nullish input is treated as LTR. */
export function containsArabic(text: string | null | undefined): boolean {
  if (!text) return false;
  return ARABIC_SCRIPT_RANGE.test(text);
}

/** `dir` attribute value appropriate for rendering this text block. */
export function directionFor(text: string | null | undefined): "rtl" | "ltr" {
  return containsArabic(text) ? "rtl" : "ltr";
}
