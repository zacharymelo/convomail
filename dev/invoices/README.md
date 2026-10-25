# Invoice-reading checks

Fixtures and CLI checks for the supplier-invoice readers in `module/class/` (`ConvomailEInvoice`, `ConvomailPdfEmbedded`, `ConvomailInvoiceAi`, `ConvomailInvoiceReader`). Nothing here calls an AI service: the AI answers are canned strings.

```bash
dev/invoices/run.sh
```

`run.sh` copies the two scripts into the Dolibarr container, runs `generate.php` (fixtures in `/tmp/cm-invoices/out`, copied back to `dev/invoices/out/`, which git ignores), then `test.php`, which exits non-zero on any failure. It needs the dev stack up and `module/vendor` installed (smalot/pdfparser).

| Fixture | What it checks |
|---|---|
| `cii.xml`, `cii-creditnote.xml`, `ubl.xml`, `ubl-creditnote.xml` | Header fields, totals, VAT breakdown, credit-note flag; IBAN present in the XML but never in the hints |
| `cii-hostile-strings.xml` | Markup, control and zero-width characters in names |
| `xxe.xml`, `xxe-parameter.xml`, `billion-laughs.xml`, `utf16-doctype.xml`, `oversized.xml`, `malformed.xml`, `not-invoice.xml` | Refused XML |
| `facturx-pdfa3.pdf` | Core TCPDF, PDF/A-3, compression off, `FileAttachment` annotation |
| `facturx-flate.pdf` | Same, not PDF/A, FlateDecode stream |
| `facturx-inline-utf16.pdf` | Hand-written PDF: Filespec inline in the annotation, named only in `/UF` as UTF-16BE |
| `facturx-badxml.pdf` | Embedded `factur-x.xml` carrying an XXE: refused, falls back to the PDF text |
| `encrypted.pdf`, `truncated.pdf`, `not-a-pdf.pdf` | Unreadable PDFs end in "nothing found" |
| `plain.pdf`, `injection.pdf` | Text extraction; prompt-injection text only ever reaches the AI as delimited data |

## The panel by hand

With the dev stack up and `run.sh` run once:

```bash
docker exec -u www-data convomail-dolibarr-1 php /tmp/cm-invoices/suppliers.php
```

```bash
python3 dev/invoices/seed_mail.py
```

`suppliers.php` creates **Fournitures Dupont SARL** (`factures@dupont.test`, the fixtures' VAT number) and **Medisupply BV** (`billing@medisupply.test`, a different VAT number from its UBL invoice). `seed_mail.py` sends five emails to `sales@convomail.test`: a Factur-X PDF from Dupont (everything filled, VAT matches), a UBL XML from Medisupply (filled, VAT warning), a Factur-X PDF from an unknown sender (filled, supplier left to pick, seller named), a plain PDF (nothing found), a CII credit note (refused). Turn on *Fill from the invoice* in Convomail setup, then Create › Supplier › Invoice on each.

## Read with AI without a provider

`mock_ai.php` is an OpenAI-style endpoint that keeps the last request in `/tmp/cm-ai-mock/last.json` and answers according to `/tmp/cm-ai-mock/mode` (`good`, `fenced`, `hostile`, `prose`, `error`, `http500`, `tokens`). `ai_setup.php` enables core's AI module and points it at the mock (service *custom*, `http://127.0.0.1:8099/v1/`). Dev stack only:

```bash
docker exec -u www-data convomail-dolibarr-1 php /tmp/cm-invoices/ai_setup.php
```

```bash
docker exec -d -u www-data convomail-dolibarr-1 php -S 127.0.0.1:8099 /tmp/cm-invoices/mock_ai.php
```

On Dolibarr 25 and later, core refuses local AI endpoints unless `conf.php` has `$dolibarr_ai_allow_local_endpoints = 2;` (dev stacks only).

Then turn on both *Fill from the invoice* and *Read with AI* in Convomail setup and open the scan email's supplier invoice panel. Readings are cached in `<documents>/convomail/temp/aiinvoice/`: delete them to call the mock again.

