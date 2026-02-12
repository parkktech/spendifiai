#!/usr/bin/env python3
"""
Generate a professional PDF tax summary for accountants.
Usage: python3 generate_tax_pdf.py <input.json> <output.pdf>
"""
import json, sys
from reportlab.lib.pagesizes import letter
from reportlab.lib.units import inch
from reportlab.lib.colors import HexColor
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, HRFlowable, PageBreak
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_RIGHT

PRIMARY   = HexColor("#1a5276")
GREEN     = HexColor("#2e7d32")
LIGHT_BG  = HexColor("#f5f7fa")
BORDER    = HexColor("#dde2e8")
TEXT      = HexColor("#333333")
MUTED     = HexColor("#666666")
WHITE     = HexColor("#ffffff")

def load_data(path):
    with open(path) as f:
        return json.load(f)

def build_styles():
    base = getSampleStyleSheet()
    base.add(ParagraphStyle("DocTitle", parent=base["Title"], fontSize=22, textColor=PRIMARY, spaceAfter=4))
    base.add(ParagraphStyle("DocSubtitle", parent=base["Normal"], fontSize=10, textColor=MUTED, spaceAfter=20))
    base.add(ParagraphStyle("SectionHead", parent=base["Heading2"], fontSize=14, textColor=PRIMARY, spaceBefore=16, spaceAfter=8))
    base["BodyText"].fontSize = 10
    base["BodyText"].textColor = TEXT
    base["BodyText"].leading = 14
    base.add(ParagraphStyle("SmallMuted", parent=base["Normal"], fontSize=8, textColor=MUTED))
    base.add(ParagraphStyle("BigNumber", parent=base["Normal"], fontSize=28, textColor=GREEN, alignment=TA_CENTER))
    base.add(ParagraphStyle("BigLabel", parent=base["Normal"], fontSize=10, textColor=MUTED, alignment=TA_CENTER))
    return base

def fmt_currency(val):
    try:
        return f"${float(val):,.2f}"
    except (TypeError, ValueError):
        return "$0.00"

def build_pdf(data, output_path):
    doc = SimpleDocTemplate(output_path, pagesize=letter,
        leftMargin=0.75*inch, rightMargin=0.75*inch,
        topMargin=0.75*inch, bottomMargin=0.75*inch)

    styles = build_styles()
    story = []

    s = data["summary"]
    p = data["profile"]
    u = data["user"]
    year = data["year"]

    # ── Title Block ──
    story.append(Paragraph(f"Tax Deduction Report — {year}", styles["DocTitle"]))
    story.append(Paragraph(
        f"Prepared for <b>{u['name']}</b> ({u['email']})<br/>"
        f"Generated {s['generated_at'][:10]} by SpendWise AI Expense Tracker",
        styles["DocSubtitle"]
    ))
    story.append(HRFlowable(width="100%", thickness=1, color=BORDER))
    story.append(Spacer(1, 12))

    # ── Big Numbers Row ──
    big_data = [
        [Paragraph(fmt_currency(s["grand_total_deductible"]), styles["BigNumber"]),
         Paragraph(fmt_currency(s["estimated_tax_savings"]), styles["BigNumber"]),
         Paragraph(str(s["total_line_items"]), styles["BigNumber"])],
        [Paragraph("Total Deductible", styles["BigLabel"]),
         Paragraph(f"Est. Tax Savings ({p.get('tax_bracket', 22)}%)", styles["BigLabel"]),
         Paragraph("Line Items", styles["BigLabel"])],
    ]
    big_table = Table(big_data, colWidths=[2.3*inch, 2.3*inch, 2.3*inch])
    big_table.setStyle(TableStyle([
        ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("BACKGROUND", (0, 0), (-1, -1), LIGHT_BG),
        ("ROUNDEDCORNERS", [8, 8, 8, 8]),
        ("TOPPADDING", (0, 0), (-1, 0), 16),
        ("BOTTOMPADDING", (0, -1), (-1, -1), 12),
    ]))
    story.append(big_table)
    story.append(Spacer(1, 20))

    # ── Taxpayer Profile ──
    story.append(Paragraph("Taxpayer Profile", styles["SectionHead"]))
    profile_data = [
        ["Employment", p.get("employment_type", "—").replace("_", " ").title()],
        ["Business", p.get("business_type", "—")],
        ["Filing Status", (p.get("filing_status") or "—").replace("_", " ").title()],
        ["Home Office", "Yes" if p.get("has_home_office") else "No"],
    ]
    prof_table = Table(profile_data, colWidths=[1.5*inch, 5.5*inch])
    prof_table.setStyle(TableStyle([
        ("FONTNAME", (0, 0), (0, -1), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 10),
        ("TEXTCOLOR", (0, 0), (0, -1), PRIMARY),
        ("TEXTCOLOR", (1, 0), (1, -1), TEXT),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
        ("LINEBELOW", (0, 0), (-1, -2), 0.5, BORDER),
    ]))
    story.append(prof_table)
    story.append(Spacer(1, 16))

    # ── Schedule C Mapping ──
    story.append(Paragraph("Schedule C Line Mapping", styles["SectionHead"]))
    story.append(Paragraph(
        "The following maps your deductible expenses to IRS Schedule C (Form 1040) lines. "
        "Your tax professional can use this as a starting reference.",
        styles["BodyText"]
    ))
    story.append(Spacer(1, 8))

    sched_header = [["Line", "Description", "Amount", "Items"]]
    sched_rows = []
    for line in data.get("schedule_c_mapping", []):
        note = ""
        if line["line"] == "24b":
            note = " (50% deductible)"
        sched_rows.append([
            f"Line {line['line']}",
            line["label"] + note,
            fmt_currency(line["total"]),
            str(sum(c.get("items", 0) for c in line.get("categories", []))),
        ])

    sched_total = sum(l["total"] for l in data.get("schedule_c_mapping", []))
    sched_rows.append(["", "TOTAL", fmt_currency(sched_total), ""])

    sched_data = sched_header + sched_rows
    sched_table = Table(sched_data, colWidths=[1*inch, 3.5*inch, 1.3*inch, 0.8*inch])
    sched_style = [
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("BACKGROUND", (0, 0), (-1, 0), PRIMARY),
        ("TEXTCOLOR", (0, 0), (-1, 0), WHITE),
        ("ALIGN", (2, 0), (3, -1), "RIGHT"),
        ("LINEBELOW", (0, 0), (-1, -2), 0.5, BORDER),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]
    # Bold the total row
    last_row = len(sched_data) - 1
    sched_style.append(("FONTNAME", (0, last_row), (-1, last_row), "Helvetica-Bold"))
    sched_style.append(("BACKGROUND", (0, last_row), (-1, last_row), LIGHT_BG))
    sched_style.append(("TEXTCOLOR", (2, last_row), (2, last_row), GREEN))
    sched_table.setStyle(TableStyle(sched_style))
    story.append(sched_table)
    story.append(Spacer(1, 16))

    # ── Deductions by Category ──
    story.append(Paragraph("Deductions by Category", styles["SectionHead"]))
    cat_header = [["Category", "Amount", "Transactions", "Date Range"]]
    cat_rows = []
    for cat in data.get("deductions_by_category", []):
        date_range = f"{cat.get('first_date', '')[:10]} to {cat.get('last_date', '')[:10]}"
        cat_rows.append([
            cat["tax_category"],
            fmt_currency(cat["total"]),
            str(cat["item_count"]),
            date_range,
        ])

    cat_data = cat_header + cat_rows
    cat_table = Table(cat_data, colWidths=[2.5*inch, 1.3*inch, 1*inch, 2*inch])
    cat_style = [
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("BACKGROUND", (0, 0), (-1, 0), HexColor("#1565c0")),
        ("TEXTCOLOR", (0, 0), (-1, 0), WHITE),
        ("ALIGN", (1, 0), (2, -1), "RIGHT"),
        ("LINEBELOW", (0, 0), (-1, -2), 0.5, BORDER),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]
    # Alternate row shading
    for i in range(1, len(cat_data)):
        if i % 2 == 0:
            cat_style.append(("BACKGROUND", (0, i), (-1, i), LIGHT_BG))
    cat_table.setStyle(TableStyle(cat_style))
    story.append(cat_table)
    story.append(Spacer(1, 16))

    # ── Linked Accounts ──
    story.append(Paragraph("Linked Financial Accounts", styles["SectionHead"]))
    acct_header = [["Institution", "Account", "Type", "Purpose", "Business / EIN"]]
    acct_rows = []
    for acct in data.get("accounts", []):
        biz_info = acct.get("business_name") or ""
        if acct.get("ein"):
            biz_info += f" (EIN: {acct['ein']})"
        acct_rows.append([
            acct.get("institution", ""),
            f"{acct.get('account', '')} ····{acct.get('mask', '')}",
            acct.get("type", ""),
            acct.get("purpose", "").title(),
            biz_info,
        ])

    acct_data = acct_header + acct_rows
    acct_table = Table(acct_data, colWidths=[1.3*inch, 1.5*inch, 0.8*inch, 0.8*inch, 2.3*inch])
    acct_table.setStyle(TableStyle([
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("BACKGROUND", (0, 0), (-1, 0), HexColor("#34495e")),
        ("TEXTCOLOR", (0, 0), (-1, 0), WHITE),
        ("LINEBELOW", (0, 0), (-1, -2), 0.5, BORDER),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    story.append(acct_table)
    story.append(Spacer(1, 24))

    # ── Disclaimer ──
    story.append(HRFlowable(width="100%", thickness=0.5, color=BORDER))
    story.append(Spacer(1, 8))
    story.append(Paragraph(
        "<b>Disclaimer:</b> This report was generated by SpendWise using AI-assisted categorization. "
        f"{s['total_line_items']} transactions were analyzed. Items marked 'AI' were categorized by "
        "machine learning and should be reviewed by a tax professional. Items marked 'User' were "
        "manually confirmed by the taxpayer. This report is not tax advice. Consult a qualified "
        "tax professional for filing decisions.",
        styles["SmallMuted"]
    ))
    story.append(Spacer(1, 4))
    story.append(Paragraph(
        "The accompanying Excel workbook contains full transaction-level detail across all tabs.",
        styles["SmallMuted"]
    ))

    doc.build(story)
    print(f"Generated: {output_path}")

def main():
    if len(sys.argv) < 3:
        print("Usage: generate_tax_pdf.py <input.json> <output.pdf>")
        sys.exit(1)
    data = load_data(sys.argv[1])
    build_pdf(data, sys.argv[2])

if __name__ == "__main__":
    main()
