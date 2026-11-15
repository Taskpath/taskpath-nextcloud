# English sample archive and documents (fictional)

Everything here is made up: a fictional Irish plumbing and heating company's
archive (`folders.txt`, `files.txt`: names only, uploaded as empty placeholder
files by `dev/demo.sh`) and eight fictional incoming documents. They mirror the
Dutch set in `dev/sample/` one for one, so the demo goes the same way in both
languages:

| English | Dutch | What it shows |
|---|---|---|
| scan_0012.pdf | scan_0012.pdf | invoice from a known supplier |
| invoice.pdf | invoice.pdf | new supplier, new folder |
| Invoice_2026_118.pdf | Factuur_2026_118.pdf | supplier written with its legal form (Ltd) |
| payslip.pdf | loonstrook.pdf | already in the archive: asks instead of guessing |
| invoice_sept.pdf | factuur_sept.pdf | contains an instruction aimed at the AI, ignored |
| creditnote.pdf | creditnota.pdf | credit note, has not earned autonomy |
| bill.pdf | nota.pdf | informal bill, date written out; Bob may file it |
| scan_0014.pdf | scan_0014.pdf | council letter, outside Bob's folder |

Regenerate with `python3 dev/sample-en/build.py` (macOS; PDFs via `cupsfilter`).
