# Noto Sans for certificates

Certificate PDFs use these fonts for names and titles in these scripts: Arabic (Arabic, Persian, Urdu and others), Hebrew, Devanagari, Bengali, Tamil and Thai (ADR-052). DejaVu Sans and Serif cover Latin, Greek and Cyrillic; they ship with mPDF in `vendor-prefixed/`.

Each family is the unhinted Regular and Bold TTF from its release on github.com/notofonts:

| Family | Release |
|---|---|
| Noto Sans Arabic | 2.013 |
| Noto Sans Hebrew | 3.001 |
| Noto Sans Devanagari | 2.007 |
| Noto Sans Bengali | 3.011 |
| Noto Sans Tamil | 2.004 |
| Noto Sans Thai | 2.002 |

All are licensed under the SIL Open Font License 1.1; each family's licence is next to it (`OFL-*.txt`). mPDF 8.3.1 reads them only with `tools/prefix/mpdf-8.3.1-publiclms.patch` applied.
