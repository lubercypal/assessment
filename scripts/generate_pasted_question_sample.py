#!/usr/bin/env python3

from __future__ import annotations

import csv
import html
import os
import re
import struct
import zipfile
import zlib
from datetime import datetime, timezone


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUTPUT_DIR = os.path.join(ROOT, "outputs")
IMPORT_DIR = os.path.join(ROOT, "storage", "imports")
XLSX_PATH = os.path.join(OUTPUT_DIR, "question_bank_sample_pasted_questions.xlsx")
CSV_PATH = os.path.join(IMPORT_DIR, "question_bank_sample_pasted_questions.csv")
DATABASE_SAMPLE_PATH = os.path.join(ROOT, "database", "question_bank_sample.csv")
IMAGE_ZIP_PATH = os.path.join(OUTPUT_DIR, "question_bank_sample_images.zip")

HEADERS = [
    "Question Code",
    "Subject",
    "Topic",
    "Group Code",
    "Passage Text",
    "Passage Image",
    "Question Text",
    "Question Image",
    "Question Type",
    "Option A Text",
    "Option A Image",
    "Option B Text",
    "Option B Image",
    "Option C Text",
    "Option C Image",
    "Option D Text",
    "Option D Image",
    "Option E Text",
    "Option E Image",
    "Correct Option",
    "Explanation",
    "Mode",
    "Active",
    "Difficulty",
    "Marks",
    "Negative Marks",
    "Scoring Rule",
    "Shuffle Options",
    "Ready For Import",
    "Notes",
]


def row(
    question_code,
    subject,
    topic,
    group_code,
    passage_text,
    passage_image,
    question_text,
    question_type,
    options,
    correct_option,
    explanation,
    mode="assessment",
    active=1,
    difficulty="medium",
    marks=1,
    negative_marks=0,
    scoring_rule="exact_match",
    shuffle_options="No",
    ready="Yes",
    notes="",
    question_image="",
):
    values = {
        "Question Code": question_code,
        "Subject": subject,
        "Topic": topic,
        "Group Code": group_code,
        "Passage Text": passage_text,
        "Passage Image": passage_image,
        "Question Text": question_text,
        "Question Image": question_image,
        "Question Type": question_type,
        "Correct Option": correct_option,
        "Explanation": explanation,
        "Mode": mode,
        "Active": active,
        "Difficulty": difficulty,
        "Marks": marks,
        "Negative Marks": negative_marks,
        "Scoring Rule": scoring_rule,
        "Shuffle Options": shuffle_options,
        "Ready For Import": ready,
        "Notes": notes,
    }
    letters = ["A", "B", "C", "D", "E"]
    for index, letter in enumerate(letters):
        text, image = options[index] if index < len(options) else ("", "")
        values[f"Option {letter} Text"] = text
        values[f"Option {letter} Image"] = image
    return values


def rows():
    solar_passage = (
        "A school installed rooftop solar panels to reduce its electricity use. "
        "In April the panels generated 1,200 units of electricity, while the "
        "school consumed 1,500 units. In May generation increased by 25 percent "
        "from April, and consumption remained unchanged. Any generation beyond "
        "consumption is exported to the electricity grid."
    )
    railway_passage = (
        "The table/image below shows the estimated cost, in Rs. lakh, of a "
        "project for laying a railway line between two places. Keep the same "
        "passage/table reference repeated for every sub-question in this set."
    )
    railway_image = "railway-cost-estimate-table.png"
    railway_note = (
        "Grouped DI question. Keep the same Group Code and keep all group rows "
        "consecutive. Their Excel row order becomes the protected sequence. "
        "Correct option is marked TBD because the source table values were not "
        "included in the pasted text."
    )

    data = [
        row(
            "LR-OBJ-001",
            "Logical Reasoning",
            "Truth and Arrangement",
            "",
            "",
            "",
            (
                "Saira, Mumtaz and Zeenat have a ball, a pen and a pencil, and "
                "each girl has just one object in hand. Among the following "
                "statements, only one is true and the other two are false.\n"
                "I. Saira has a ball.\n"
                "II. Mumtaz does not have the ball.\n"
                "III. Zeenat does not have the pen.\n"
                "Who has the ball?"
            ),
            "single",
            [("Saira", ""), ("Mumtaz", ""), ("Zeenat", ""), ("Cannot be determined", "")],
            "B",
            (
                "If Saira has the ball, statements I and II become true. If "
                "Zeenat has the ball, statements II and III become true. Only "
                "Mumtaz having the ball makes exactly one statement true."
            ),
            mode="demo",
            difficulty="medium",
        ),
        row(
            "LR-ORDER-056",
            "Logical Reasoning",
            "Ordering and Inequality",
            "",
            "",
            "",
            (
                "Albert, David, Jerome and Tommy were plucking mangoes. Their "
                "earnings were directly related to the number of mangoes plucked. "
                "Jerome got less money than Tommy. Jerome and Tommy together got "
                "the same amount as Albert and David together. Albert and Tommy "
                "together got less than David and Jerome together. Who earned the "
                "most pocket money? Who plucked the least number of mangoes?"
            ),
            "single",
            [
                ("David, Jerome", ""),
                ("David, Albert", ""),
                ("Jerome, Tommy", ""),
                ("Jerome, Albert", ""),
            ],
            "B",
            (
                "Let earnings be A, D, J, T. Given J < T and J + T = A + D. "
                "From A + T < D + J and D = J + T - A, we get A < J. Therefore "
                "A < J < T and D > T, so David earned most and Albert least."
            ),
            mode="demo",
            difficulty="hard",
        ),
        row(
            "LR-MULTI-057",
            "Logical Reasoning",
            "Number Classification",
            "",
            "",
            "",
            "Which of the following numbers are prime numbers? Select all correct options.",
            "multi",
            [("2", ""), ("3", ""), ("9", ""), ("11", "")],
            "A,B,D",
            "The numbers 2, 3, and 11 have exactly two positive factors. The number 9 is composite.",
            marks=3,
            negative_marks=1,
            scoring_rule="partial_credit",
            shuffle_options="Yes",
            mode="demo",
            difficulty="medium",
            notes=(
                "Partial-credit example: A,B,D earns 3 marks; selecting only A,B "
                "earns 2 marks; selecting any incorrect option such as C earns -1 mark."
            ),
        ),
        row(
            "IMG-SAMPLE-001",
            "Logical Reasoning",
            "Visual Classification",
            "",
            "",
            "",
            "The reference image shows the target color. Which option matches it?",
            "single",
            [
                ("Blue", "blue-option.png"),
                ("Red", "red-option.png"),
                ("Green", "green-option.png"),
                ("Yellow", "yellow-option.png"),
            ],
            "A",
            "The reference image and option A both show blue.",
            mode="demo",
            difficulty="easy",
            shuffle_options="Yes",
            question_image="sample-color-guide.png",
            notes=(
                "Ready image example. Upload question_bank_sample_images.zip with "
                "the CSV; filenames may be inside any safe folders in the ZIP."
            ),
        ),
        row(
            "PASS-SOLAR-001-01",
            "Logical Reasoning",
            "Passage Analysis",
            "PASS-SOLAR-001",
            solar_passage,
            "",
            "How many electricity units did the school obtain from the grid in April?",
            "single",
            [("200 units", ""), ("300 units", ""), ("500 units", ""), ("1,200 units", "")],
            "B",
            "April consumption exceeded solar generation by 1,500 - 1,200 = 300 units.",
            mode="assessment",
            difficulty="easy",
            shuffle_options="Yes",
            notes="Ready paragraph group. Keep all PASS-SOLAR-001 rows consecutive.",
        ),
        row(
            "PASS-SOLAR-001-02",
            "Logical Reasoning",
            "Passage Analysis",
            "PASS-SOLAR-001",
            solar_passage,
            "",
            "How many electricity units did the panels generate in May?",
            "single",
            [("1,200 units", ""), ("1,350 units", ""), ("1,500 units", ""), ("1,800 units", "")],
            "C",
            "A 25 percent increase on 1,200 units is 300 units, giving 1,500 units.",
            mode="assessment",
            difficulty="easy",
            shuffle_options="Yes",
            notes="Ready paragraph group. Excel row order becomes protected group sequence.",
        ),
        row(
            "PASS-SOLAR-001-03",
            "Logical Reasoning",
            "Passage Analysis",
            "PASS-SOLAR-001",
            solar_passage,
            "",
            "Which statements are correct for May? Select all correct options.",
            "multi",
            [
                ("Solar generation equalled consumption.", ""),
                ("The school imported 300 units from the grid.", ""),
                ("No electricity was exported to the grid.", ""),
                ("Generation was 25 percent higher than in April.", ""),
            ],
            "A,C,D",
            "May generation was 1,500 units, equal to consumption, so there was no import or export.",
            mode="assessment",
            difficulty="medium",
            marks=3,
            negative_marks=1,
            scoring_rule="partial_credit",
            shuffle_options="Yes",
            notes="Ready multi-select member of the protected paragraph group.",
        ),
        row(
            "DI-RAIL-061",
            "Logical Reasoning",
            "Data Interpretation",
            "DI-RAILWAY-COST-001",
            railway_passage,
            railway_image,
            (
                "The total expenditure is required to be kept within Rs. 700 lakh "
                "by cutting the expenditure on administration equally in all the "
                "years. What will be the percentage cut for 1989?"
            ),
            "single",
            [("22.6", ""), ("32.6", ""), ("42.5", ""), ("52.6", "")],
            "TBD",
            "Fill after checking the railway cost table.",
            ready="No",
            notes=railway_note,
        ),
        row(
            "DI-RAIL-062",
            "Logical Reasoning",
            "Data Interpretation",
            "DI-RAILWAY-COST-001",
            railway_passage,
            railway_image,
            (
                "If the length of the line to be laid each year is proportional "
                "to the estimated cost for cement, steel, bricks, building "
                "material and labour, what fraction of the total length is "
                "proposed to be completed by the third year?"
            ),
            "single",
            [("0.9", ""), ("0.7", ""), ("0.6", ""), ("0.3", "")],
            "TBD",
            "Fill after checking the railway cost table.",
            ready="No",
            notes=railway_note,
        ),
        row(
            "DI-RAIL-063",
            "Logical Reasoning",
            "Data Interpretation",
            "DI-RAILWAY-COST-001",
            railway_passage,
            railway_image,
            (
                "What is the approximate ratio of the total cost of materials "
                "(cement, steel, bricks, building materials) for all the years "
                "to the total labour cost?"
            ),
            "single",
            [("4 : 1", ""), ("8 : 1", ""), ("12 : 1", ""), ("16 : 1", "")],
            "TBD",
            "Fill after checking the railway cost table.",
            ready="No",
            notes=railway_note,
        ),
        row(
            "DI-RAIL-064",
            "Logical Reasoning",
            "Data Interpretation",
            "DI-RAILWAY-COST-001",
            railway_passage,
            railway_image,
            (
                "If the cost of materials (cement, steel, bricks, building "
                "materials) rises by 5% each year from 1990 onwards, by how "
                "much will the estimated cost rise for the years 1990 and 1991?"
            ),
            "single",
            [
                ("Rs. 11.45 lakh", ""),
                ("Rs. 16.35 lakh", ""),
                ("Rs. 21.45 lakh", ""),
                ("Rs. 26.45 lakh", ""),
            ],
            "TBD",
            "Fill after checking the railway cost table.",
            ready="No",
            notes=railway_note,
        ),
        row(
            "DI-RAIL-065",
            "Logical Reasoning",
            "Data Interpretation",
            "DI-RAILWAY-COST-001",
            railway_passage,
            railway_image,
            (
                "It is found at the end of 1990 that the entire amount estimated "
                "for the project has been spent. If for 1991, the actual amount "
                "spent was equal to that which was estimated, by what percent "
                "(approximately) has the actual expenditure exceeded the "
                "estimated expenditure?"
            ),
            "single",
            [("39", ""), ("29", ""), ("19", ""), ("9", "")],
            "TBD",
            "Fill after checking the railway cost table.",
            ready="No",
            notes=railway_note,
        ),
        row(
            "DI-RAIL-066",
            "Logical Reasoning",
            "Data Interpretation",
            "DI-RAILWAY-COST-001",
            railway_passage,
            railway_image,
            (
                "After preparing the estimate, the provision for contingencies "
                "is felt inadequate and is therefore doubled. By what percent "
                "does the total estimate increase?"
            ),
            "single",
            [("3.47", ""), ("2.45", ""), ("1.50", ""), ("3.62", "")],
            "TBD",
            "Fill after checking the railway cost table.",
            ready="No",
            notes=railway_note,
        ),
    ]
    return data


def clean_text(value):
    text = "" if value is None else str(value)
    return re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f]", "", text)


def xml_escape(value):
    return html.escape(clean_text(value), quote=True)


def col_name(index):
    name = ""
    while index:
        index, rem = divmod(index - 1, 26)
        name = chr(65 + rem) + name
    return name


def cell_xml(row_num, col_num, value, style=0):
    ref = f"{col_name(col_num)}{row_num}"
    style_attr = f' s="{style}"' if style else ""
    if isinstance(value, int) and not isinstance(value, bool):
        return f'<c r="{ref}"{style_attr}><v>{value}</v></c>'
    return (
        f'<c r="{ref}" t="inlineStr"{style_attr}>'
        f'<is><t xml:space="preserve">{xml_escape(value)}</t></is></c>'
    )


def worksheet_xml(sheet_rows, widths, freeze=True, autofilter_ref=None):
    col_xml = "".join(
        f'<col min="{i}" max="{i}" width="{width}" customWidth="1"/>'
        for i, width in enumerate(widths, start=1)
    )
    views = ""
    if freeze:
        views = (
            '<sheetViews><sheetView workbookViewId="0">'
            '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            '</sheetView></sheetViews>'
        )
    rows_xml = []
    for r_idx, values in enumerate(sheet_rows, start=1):
        style = 1 if r_idx == 1 else 0
        height = ' ht="66" customHeight="1"' if r_idx > 1 else ' ht="28" customHeight="1"'
        cells = "".join(cell_xml(r_idx, c_idx, value, style) for c_idx, value in enumerate(values, start=1))
        rows_xml.append(f'<row r="{r_idx}"{height}>{cells}</row>')
    auto_filter = f'<autoFilter ref="{autofilter_ref}"/>' if autofilter_ref else ""
    return (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        f'{views}<cols>{col_xml}</cols><sheetData>{"".join(rows_xml)}</sheetData>{auto_filter}'
        '</worksheet>'
    )


def build_xlsx(data):
    question_rows = [HEADERS] + [[row[h] for h in HEADERS] for row in data]
    instructions = [
        ["Field", "How to use"],
        ["Question Code", "Unique stable ID such as LR-OBJ-001 or DI-RAIL-061."],
        ["Group Code", "Blank for standalone questions. Same value for all questions in a paragraph/table series."],
        ["Group Sequence", "There is no order column. Keep group rows consecutive; Excel row order becomes their protected sequence."],
        ["Passage Text", "Repeat the paragraph/table instruction for every question in the group."],
        ["Passage Image", "Enter the matching ZIP filename, such as railway-cost-estimate-table.png."],
        ["Question Image", "Optional image below the question text."],
        ["Option Text/Image", "If text exists, show text. If image exists, show image. If both exist, show text as title and image below."],
        ["Correct Option", "Use A for single answer, or A,C for multi-select. TBD means not ready for import."],
        ["Marks", "Positive marks awarded when the answer satisfies the scoring rule."],
        ["Negative Marks", "Deduction applied to an answered response that includes a wrong option or fails exact_match."],
        ["Scoring Rule", "Use exact_match or partial_credit. Blank defaults to exact_match."],
        ["exact_match", "Full marks only when the selected set exactly matches Correct Option; otherwise deduct Negative Marks."],
        ["partial_credit", "A correct subset with no wrong option earns proportional marks; any wrong selected option deducts Negative Marks."],
        ["Shuffle Options", "Use Yes to randomize option order once per attempt, or No to keep A, B, C order."],
        ["Ready For Import", "Use Yes only after correct option, media path, and explanation are final."],
        ["Randomization Rule", "Standalone questions and complete groups may shuffle. Questions inside a group stay in consecutive Excel row order."],
        ["Image ZIP", "ZIP image names must match workbook image cells. The importer validates, converts to WebP, and creates a unique server batch folder."],
    ]

    files = {
        "[Content_Types].xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            '<Default Extension="xml" ContentType="application/xml"/>'
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            "</Types>"
        ),
        "_rels/.rels": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            "</Relationships>"
        ),
        "xl/workbook.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            "<sheets>"
            '<sheet name="Question Bank" sheetId="1" r:id="rId1"/>'
            '<sheet name="Instructions" sheetId="2" r:id="rId2"/>'
            "</sheets></workbook>"
        ),
        "xl/_rels/workbook.xml.rels": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            "</Relationships>"
        ),
        "xl/styles.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font></fonts>'
            '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF116466"/></patternFill></fill></fills>'
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            "</styleSheet>"
        ),
        "xl/worksheets/sheet1.xml": worksheet_xml(
            question_rows,
            [18, 20, 22, 24, 58, 38, 66, 32, 16, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 16, 58, 14, 10, 14, 10, 16, 18, 16, 16, 58],
            freeze=True,
            autofilter_ref=f"A1:AD{len(question_rows)}",
        ),
        "xl/worksheets/sheet2.xml": worksheet_xml(instructions, [24, 120], freeze=True, autofilter_ref=f"A1:B{len(instructions)}"),
        "docProps/core.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            'xmlns:dcterms="http://purl.org/dc/terms/" '
            'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            "<dc:title>Sample Assessment Question Bank</dc:title>"
            "<dc:creator>Assessment Portal</dc:creator>"
            f'<dcterms:created xsi:type="dcterms:W3CDTF">{datetime.now(timezone.utc).isoformat()}</dcterms:created>'
            "</cp:coreProperties>"
        ),
        "docProps/app.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            "<Application>Assessment Portal</Application>"
            '<TitlesOfParts><vt:vector size="2" baseType="lpstr"><vt:lpstr>Question Bank</vt:lpstr><vt:lpstr>Instructions</vt:lpstr></vt:vector></TitlesOfParts>'
            "</Properties>"
        ),
    }

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    with zipfile.ZipFile(XLSX_PATH, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, content in files.items():
            archive.writestr(name, content)


def build_csv(data):
    os.makedirs(IMPORT_DIR, exist_ok=True)
    for path in (CSV_PATH, DATABASE_SAMPLE_PATH):
        with open(path, "w", newline="", encoding="utf-8") as handle:
            writer = csv.DictWriter(handle, fieldnames=HEADERS, lineterminator="\n")
            writer.writeheader()
            writer.writerows(data)


def png_bytes(width, height, color):
    red, green, blue = color
    raw = b"".join(b"\x00" + bytes((red, green, blue)) * width for _ in range(height))

    def chunk(kind, payload):
        return (
            struct.pack(">I", len(payload))
            + kind
            + payload
            + struct.pack(">I", zlib.crc32(kind + payload) & 0xFFFFFFFF)
        )

    return (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
        + chunk(b"IDAT", zlib.compress(raw, 9))
        + chunk(b"IEND", b"")
    )


def build_image_zip():
    images = {
        "reference/sample-color-guide.png": (39, 111, 191),
        "options/blue-option.png": (39, 111, 191),
        "options/red-option.png": (204, 55, 55),
        "options/green-option.png": (34, 139, 94),
        "options/yellow-option.png": (230, 177, 36),
    }
    with zipfile.ZipFile(IMAGE_ZIP_PATH, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, color in images.items():
            archive.writestr(name, png_bytes(480, 220, color))


def main():
    data = rows()
    build_xlsx(data)
    build_csv(data)
    build_image_zip()
    print(XLSX_PATH)
    print(CSV_PATH)
    print(DATABASE_SAMPLE_PATH)
    print(IMAGE_ZIP_PATH)
    print(f"rows={len(data)}")


if __name__ == "__main__":
    main()
