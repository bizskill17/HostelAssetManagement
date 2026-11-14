"""Workflow training videos. Run after the Masters videos (they create the Foundation, Lily Hostel, Electrical, Nos, Scrap dealer, Ceiling Fan)."""
import sys, os, openpyxl, urllib.request
import api
from rec import record

WH = "Ajmal Foundation South – Central Warehouse"


def base_setup():
    api.login()
    d = api.data()
    names = {h["name"] for h in d["hostels"]}
    fid = d["foundations"][0]["id"]
    if "Nilgiri Hostel" not in names:
        api.master("hostels", {"name": "Nilgiri Hostel", "foundationId": fid, "unitType": "Hostel", "address": "", "active": True})
    cats = {c["name"] for c in d["categories"]}
    if "Furniture" not in cats:
        api.master("categories", {"name": "Furniture", "warranty": False})
    items = {i["name"] for i in api.data()["items"]}
    for n in ["Single Bed", "Study Table"]:
        if n not in items:
            api.master("items", {"name": n, "categoryId": api.cid("Furniture"), "unit": "Nos", "notes": "", "active": True})


def ensure_stock():
    """Stock used by the Out Register videos."""
    api.login()
    d = api.data()
    have = {(t["hostelId"], t["itemId"], t["kind"]) for t in d["txns"]}
    wh, lily, bed = api.hid(WH), api.hid("Lily Hostel"), api.iid("Single Bed")
    if (wh, bed, "in") not in have:
        api.call("stock.add", {"kind": "in", "hostelId": wh, "itemId": bed, "qty": 50, "notes": "Bill 1024", "party": "Sharma Furniture"})
    if (lily, bed, "opening") not in have:
        api.call("stock.add", {"kind": "opening", "hostelId": lily, "itemId": bed, "qty": 20, "notes": ""})
    sers = {w["warrantyNo"] for w in d["warranties"]}
    fan = api.iid("Ceiling Fan 48 inch")
    for sn in ["FAN-1001", "FAN-1002"]:
        if sn not in sers:
            api.call("stock.add", {"kind": "in", "hostelId": wh, "itemId": fan, "qty": 1, "notes": "Bill 1025", "party": "Bajaj", "warrantyNo": sn, "warrantyExpiry": "2028-11-14"})


V = {}

V["signin"] = ("sign-in", lambda: None, [
    {"say": "Welcome to Hostel Asset Management. This video shows how to sign in and sign out.", "cap": "Sign In and Sign Out", "num": False},
    {"say": "Open the app. Type the email address your Admin gave you.", "cap": "Type your Email", "do": [("type", "input[name=email]", "admin@example.com")]},
    {"say": "Type your password. Click the eye icon if you want to check what you typed.", "cap": "Type your Password", "do": [("type", "input[name=password]", "admin1234"), ("hl", "form .eye")]},
    {"say": "Click Sign In.", "cap": "Click Sign In", "do": [("click", "form button[type=submit]"), ("wait", 1000)]},
    {"say": "You are now on the Dashboard. Your name and role are shown at the bottom of the menu.", "cap": "You are on the Dashboard", "do": [("hl", "#meBox")]},
    {"say": "When you are done, click the sign out button next to your name.", "cap": "Sign out: button next to your name", "do": [("hl", "#meBox [data-act=logout]")]},
])

V["start"] = ("getting-started", lambda: None, [
    {"say": "This short video shows how to find your way around the app.", "cap": "Find Your Way Around", "num": False},
    {"say": "The left menu has four parts. Overview has the Dashboard and Inventory.", "cap": "Overview: Dashboard and Inventory", "do": [("hl", "#nav .sec")]},
    {"say": "Operations has the In Register for stock coming in, and the Out Register for transfers, repairs and damage.", "cap": "Operations: In Register and Out Register", "do": [("hl", "#nav a[href='#stock']"), ("hl", "#nav a[href='#issues']")]},
    {"say": "Records has Warranties.", "cap": "Records: Warranties", "do": [("hl", "#nav a[href='#warranties']")]},
    {"say": "Masters and Settings are for the Admin, to set up foundations, units, items and users.", "cap": "Masters and Settings (Admin)", "do": [("hl", "#nav a[href='#foundations']")]},
    {"say": "The Dashboard shows what needs attention. Click any tile to open that list.", "cap": "Dashboard tiles open the list", "do": [("click", "#nav a[href='#home']"), ("hl", "#view .stats.dash")]},
    {"say": "On a phone, the bar at the bottom has Home, Inventory, Issue, Approvals and More.", "cap": "On a phone: the bottom bar", "do": [("wait", 300)]},
])

V["purchase"] = ("in-purchase", lambda: None, [
    {"say": "In this video, you will learn how to enter a purchase in the In Register.", "cap": "Add a Purchase", "num": False},
    {"say": "Under Operations, click In Register.", "cap": "Click Operations → In Register", "do": [("click", "#nav a[href='#stock']")]},
    {"say": "Click the blue Add Stock button.", "cap": "Click + Add Stock", "do": [("click", "#view .ph .btn.pri")]},
    {"say": "Keep the type as Purchase.", "cap": "Type: Purchase", "do": [("seg", "kind", "in")]},
    {"say": "Choose where the stock arrives: the Central Warehouse or a unit.", "cap": "Choose the Unit / Warehouse", "do": [("pick", "hostelId", WH)]},
    {"say": "Choose the item.", "cap": "Choose the Item", "do": [("pick", "itemId", "Study Table · Furniture")]},
    {"say": "Enter the quantity and the purchase date.", "cap": "Enter Quantity and Purchase Date", "do": [("type", ".dlg input[name=qty]", "15")]},
    {"say": "Enter the supplier and the bill number in notes.", "cap": "Supplier and Notes (bill number)", "do": [("type", ".dlg input[name=party]", "Sharma Furniture"), ("type", ".dlg textarea[name=notes]", "Bill 1031")]},
    {"say": "Click Add Stock. The quantity is added to stock straight away.", "cap": "Click Add Stock to save", "do": [("click", ".dlg button[type=submit]"), ("wait", 600), ("hl", "#view .tw tbody tr")]},
])

V["opening"] = ("in-opening-stock", lambda: None, [
    {"say": "In this video, you will learn how to enter opening stock, the items a unit already has when you start.", "cap": "Add Opening Stock", "num": False},
    {"say": "Open the In Register and click Add Stock.", "cap": "In Register → + Add Stock", "do": [("click", "#nav a[href='#stock']"), ("click", "#view .ph .btn.pri")]},
    {"say": "Choose Opening stock as the type.", "cap": "Type: Opening Stock", "do": [("seg", "kind", "opening")]},
    {"say": "Choose the unit, and the item.", "cap": "Choose the Unit and the Item", "do": [("pick", "hostelId", "Nilgiri Hostel"), ("pick", "itemId", "Study Table · Furniture")]},
    {"say": "Enter the quantity, and the date the count was taken.", "cap": "Enter Quantity and As Of Date", "do": [("type", ".dlg input[name=qty]", "12")]},
    {"say": "Click Add Stock. Opening stock is entered once per item for each unit. Anything later is entered as a purchase.", "cap": "Click Add Stock — once per item per unit", "do": [("click", ".dlg button[type=submit]"), ("wait", 600), ("hl", "#view .tw tbody tr")]},
])

V["warranty_in"] = ("in-warranty-item", lambda: None, [
    {"say": "In this video, you will learn how to enter an item that has a warranty, like a fan or a generator.", "cap": "Add a Warranty Item", "num": False},
    {"say": "Open the In Register and click Add Stock.", "cap": "In Register → + Add Stock", "do": [("click", "#nav a[href='#stock']"), ("click", "#view .ph .btn.pri")]},
    {"say": "Choose the warehouse and the item. Because its category has a warranty, the quantity is fixed at one.", "cap": "Choose the Item — Quantity is fixed at 1", "do": [("pick", "hostelId", WH), ("pick", "itemId", "Ceiling Fan 48 inch · Electrical"), ("hl", ".dlg input[name=qty]")]},
    {"say": "Enter the supplier and the serial number of this piece.", "cap": "Enter Supplier and Serial No.", "do": [("type", ".dlg input[name=party]", "Bajaj"), ("type", ".dlg input[name=warrantyNo]", "FAN-1003")]},
    {"say": "Enter when the warranty expires, and the bill number in notes.", "cap": "Enter Warranty Expiry and Notes", "do": [("fill", ".dlg input[name=warrantyExpiry]", "2028-11-30"), ("type", ".dlg textarea[name=notes]", "Bill 1032")]},
    {"say": "Click Add Stock. The warranty is added to the Warranties page automatically. For more pieces, repeat this once for each serial number.", "cap": "Add Stock — warranty is added automatically", "do": [("click", ".dlg button[type=submit]"), ("wait", 600), ("hl", "#view .tw tbody tr")]},
])


def make_bulk_file():
    api.login()
    req = urllib.request.Request(api.B + "stock.template", headers={"X-Req": "1"})
    open("/tmp/tpl_demo.xlsx", "wb").write(api.op.open(req).read())
    wb = openpyxl.load_workbook("/tmp/tpl_demo.xlsx")
    ws = wb.active
    import datetime
    ws.append(["Lily Hostel", "Study Table", 8, datetime.datetime(2026, 4, 1), None, None, None, None, "Opening count"])
    ws.append(["Nilgiri Hostel", "Single Bed", 30, datetime.datetime(2026, 4, 1), None, None, None, None, "Opening count"])
    wb.save("/tmp/opening-stock.xlsx")


V["bulk"] = ("in-bulk-upload", make_bulk_file, [
    {"say": "In this video, you will learn how to load opening stock for many items at once from an Excel file.", "cap": "Bulk Upload Opening Stock", "num": False},
    {"say": "Open the In Register and click Bulk Upload.", "cap": "In Register → Bulk Upload", "do": [("click", "#nav a[href='#stock']"), ("click", "#view [data-act=bulkup]")]},
    {"say": "Click Download Template. The Excel file has dropdowns for the unit or warehouse, and for the item.", "cap": "Click Download Template", "do": [("click", "#bkTpl"), ("wait", 800)]},
    {"say": "Fill one row for each unit and item, with the quantity and date, and save the file.", "cap": "Fill one row per Unit and Item, then save", "do": [("wait", 400)]},
    {"say": "Back in the app, choose the filled file. Every row is checked first.", "cap": "Upload the Filled File", "do": [("file", "#bkFile", "/tmp/opening-stock.xlsx")]},
    {"say": "When all rows are ready, click Import. If any row has a problem, it is listed and nothing is saved until you fix it.", "cap": "All rows ready → click Import", "do": [("hl", "#bkOut"), ("click", "#bkGo"), ("wait", 900), ("hl", "#view .tw tbody tr")]},
])

V["transfer"] = ("out-transfer", ensure_stock, [
    {"say": "In this video, you will learn how to transfer items from the Central Warehouse to a unit. The same steps work between any two units or warehouses.", "cap": "Transfer Items", "num": False},
    {"say": "Under Operations, click Out Register, then Issue Asset.", "cap": "Out Register → + Issue Asset", "do": [("click", "#nav a[href='#issues']"), ("click", "#view .ph .btn.pri")]},
    {"say": "Keep Transfer selected, and choose where the items are leaving from.", "cap": "Transfer · choose From", "do": [("seg", "kind", "transfer"), ("pick", "hostelId", WH)]},
    {"say": "Choose the item. The available quantity is shown under it.", "cap": "Choose the Item", "do": [("pick", "itemId", "Single Bed · Furniture")]},
    {"say": "Enter the quantity to send.", "cap": "Enter the Quantity", "do": [("type", ".dlg input[name=qty]", "10")]},
    {"say": "Choose where the items are going.", "cap": "Choose To (Unit or Warehouse)", "do": [("pick", "toHostelId", "Lily Hostel")]},
    {"say": "Add a note if you like, and click Issue.", "cap": "Click Issue", "do": [("type", ".dlg textarea[name=notes]", "For new rooms"), ("click", ".dlg button[type=submit]")]},
    {"say": "The transfer now waits for Lily Hostel to confirm how many arrived. Stock moves only when they update the received quantity.", "cap": "Waiting for the receiver to update Received Qty", "do": [("wait", 600), ("hl", "#view .tw tbody tr")]},
])

V["warranty_out"] = ("out-warranty-item", ensure_stock, [
    {"say": "In this video, you will learn how to issue an item that has a warranty, by choosing its serial number.", "cap": "Issue a Warranty Item", "num": False},
    {"say": "Open the Out Register and click Issue Asset.", "cap": "Out Register → + Issue Asset", "do": [("click", "#nav a[href='#issues']"), ("click", "#view .ph .btn.pri")]},
    {"say": "Choose where it is leaving from.", "cap": "Choose From", "do": [("pick", "hostelId", WH)]},
    {"say": "In the item list, every warranty piece is shown with its serial number in brackets. Choose the exact piece.", "cap": "Choose the piece: Item(Serial No.)", "do": [("pick", "itemId", "Ceiling Fan 48 inch(FAN-1001) · Electrical")]},
    {"say": "The quantity stays at one. Choose where it is going, and click Issue.", "cap": "Choose To, then Issue", "do": [("pick", "toHostelId", "Lily Hostel"), ("click", ".dlg button[type=submit]")]},
    {"say": "The entry shows the serial number, and the warranty moves with the piece when it is received.", "cap": "Serial No. shown on the entry", "do": [("wait", 600), ("hl", "#view .tw tbody tr")]},
])

V["repair"] = ("out-repair", ensure_stock, [
    {"say": "In this video, you will learn how to send items for repair.", "cap": "Send for Repair", "num": False},
    {"say": "Open the Out Register and click Issue Asset, then choose Repair.", "cap": "Out Register → + Issue Asset → Repair", "do": [("click", "#nav a[href='#issues']"), ("click", "#view .ph .btn.pri"), ("seg", "kind", "repair")]},
    {"say": "Choose the unit and the item, and enter the quantity.", "cap": "Choose From, Item and Quantity", "do": [("pick", "hostelId", "Lily Hostel"), ("pick", "itemId", "Single Bed · Furniture"), ("type", ".dlg input[name=qty]", "2")]},
    {"say": "Choose the repair type. External means an outside repairer. Internal means your own staff.", "cap": "Repair Type: External or Internal", "do": [("seg", "repairType", "Internal"), ("seg", "repairType", "External")]},
    {"say": "Enter who it is sent to, and the expected return date.", "cap": "Sent To and Expected Back", "do": [("type", ".dlg input[name=place]", "Sharma Repairs"), ("fill", ".dlg input[name=expectedAt]", "2026-11-25")]},
    {"say": "Click Issue. The items stay on the repair list until they come back.", "cap": "Click Issue", "do": [("click", ".dlg button[type=submit]"), ("wait", 600), ("hl", "#view .tw tbody tr")]},
])

V["damage"] = ("out-damage", ensure_stock, [
    {"say": "In this video, you will learn how to report damaged items.", "cap": "Mark Items Damaged", "num": False},
    {"say": "Open the Out Register and click Issue Asset, then choose Damaged.", "cap": "Out Register → + Issue Asset → Damaged", "do": [("click", "#nav a[href='#issues']"), ("click", "#view .ph .btn.pri"), ("seg", "kind", "damage")]},
    {"say": "Choose the unit and the item, and enter how many are damaged.", "cap": "Choose From, Item and Quantity", "do": [("pick", "hostelId", "Lily Hostel"), ("pick", "itemId", "Single Bed · Furniture"), ("type", ".dlg input[name=qty]", "1")]},
    {"say": "Choose where the damaged items are sent, and add a note.", "cap": "Sent To After Damage, and Notes", "do": [("pick", "dplace", "Scrap dealer"), ("type", ".dlg textarea[name=notes]", "Frame broken")]},
    {"say": "Click Issue. The report waits for approval, and stock goes down only when it is approved.", "cap": "Click Issue — waits for approval", "do": [("click", ".dlg button[type=submit]"), ("wait", 600), ("hl", "#view .tw tbody tr")]},
])

V["receive"] = ("approve-receive-transfer", None, [
    {"say": "In this video, you will learn how the receiving unit updates the quantity that arrived in a transfer.", "cap": "Receive a Transfer", "num": False},
    {"say": "Open the Out Register, click Approval, and choose Transfer.", "cap": "Out Register → Approval → Transfer", "do": [("click", "#nav a[href='#issues']"), ("click", "#view [data-m=appr]"), ("click", ".apit[data-v=transfer]")]},
    {"say": "On the transfer, click the blue tick button.", "cap": "Click the blue tick (Update Received Qty)", "do": [("click", "#view .tw tbody tr:has-text('Single Bed') [data-act=receive]")]},
    {"say": "Enter how many actually arrived. If fewer arrived, add a note. The rest stay with the sender.", "cap": "Enter Received Qty (note if fewer)", "do": [("clear", ".dlg input[name=receivedQty]", "9"), ("type", ".dlg textarea[name=note]", "One bed broken in transit")]},
    {"say": "Click Update. Stock moves to the receiving unit.", "cap": "Click Update — stock moves", "do": [("click", ".dlg button[type=submit]"), ("wait", 600)]},
])

V["dmg_approve"] = ("approve-damage", None, [
    {"say": "In this video, you will learn how to approve or reject a damage report.", "cap": "Approve or Reject Damage", "num": False},
    {"say": "Open the Out Register, click Approval, and choose Damage.", "cap": "Out Register → Approval → Damage", "do": [("click", "#nav a[href='#issues']"), ("click", "#view [data-m=appr]"), ("click", ".apit[data-v=damage]")]},
    {"say": "To approve, click the green tick. To reject, click the orange button and give a reason.", "cap": "Green tick = Approve · Orange = Reject", "do": [("hl", "#view .tw tbody tr [data-act=dmgno]"), ("click", "#view .tw tbody tr [data-act=dmgok]")]},
    {"say": "Check the approved quantity. You can approve fewer than reported.", "cap": "Check the Approved Qty", "do": [("hl", ".dlg input[name=qty]"), ("type", ".dlg textarea[name=note]", "Checked on site")]},
    {"say": "Click Approve. The items are written off from stock.", "cap": "Click Approve", "do": [("click", ".dlg button[type=submit]"), ("wait", 600)]},
])

V["repair_return"] = ("approve-repair-return", None, [
    {"say": "In this video, you will learn how to record items coming back from repair.", "cap": "Record a Repair Return", "num": False},
    {"say": "Open the Out Register, click Approval, and choose Repair.", "cap": "Out Register → Approval → Repair", "do": [("click", "#nav a[href='#issues']"), ("click", "#view [data-m=appr]"), ("click", ".apit[data-v=repair]")]},
    {"say": "On the repair, click the green return button.", "cap": "Click the green Return button", "do": [("click", "#view .tw tbody tr:has-text('Single Bed') [data-act=return]")]},
    {"say": "Enter how many came back repaired, and how many could not be repaired.", "cap": "Repaired and Not Repairable quantities", "do": [("clear", ".dlg input[name=okQty]", "1"), ("clear", ".dlg input[name=scrapQty]", "1")]},
    {"say": "Items that could not be repaired go to damage approval. Choose where they are sent.", "cap": "Not repairable → Damage approval", "do": [("pick", "place", "Scrap dealer")]},
    {"say": "Click Save Return. Repaired items go back into stock.", "cap": "Click Save Return", "do": [("click", ".dlg button[type=submit]"), ("wait", 600)]},
])


def make_pending_transfer():
    ensure_stock()
    api.call("issue.create", {"kind": "transfer", "hostelId": api.hid(WH), "itemId": api.iid("Single Bed"), "qty": 5, "toHostelId": api.hid("Nilgiri Hostel"), "notes": ""})


V["cancel"] = ("out-cancel", make_pending_transfer, [
    {"say": "In this video, you will learn how to cancel a transfer or damage report that is still waiting.", "cap": "Cancel a Waiting Entry", "num": False},
    {"say": "Open the Out Register and find the entry. It must still be waiting.", "cap": "Find the waiting entry", "do": [("click", "#nav a[href='#issues']"), ("hl", "#view .tw tbody tr:has-text('Nilgiri')")]},
    {"say": "Click the grey cancel button.", "cap": "Click the grey X (Cancel)", "do": [("click", "#view .tw tbody tr:has-text('Nilgiri') [data-act=cancel]")]},
    {"say": "Confirm. Nothing moves in stock.", "cap": "Confirm — stock does not change", "do": [("click", ".dlg [data-yes]"), ("wait", 600)]},
])

V["inventory"] = ("inventory", None, [
    {"say": "In this video, you will learn how to read the Inventory and an item's history.", "cap": "Inventory and Item History", "num": False},
    {"say": "Under Overview, click Inventory. Each row shows a unit and item, with opening, in, issued, damaged, and the current count.", "cap": "Overview → Inventory", "do": [("click", "#nav a[href='#inventory']"), ("hl", "#view .tw thead")]},
    {"say": "Use the filters to pick a unit or category, and Download to get the list as a file.", "cap": "Filters and Download", "do": [("hl", "#view .filters"), ("hl", "#view [data-act=csv]")]},
    {"say": "Click an item name to see its full history, with a running balance.", "cap": "Click an item for its history", "do": [("click", "#view .tw tbody tr .lnk[data-act=ledger]"), ("wait", 900)]},
])

V["warranties"] = ("warranties", None, [
    {"say": "In this video, you will learn how to check warranties.", "cap": "Warranties", "num": False},
    {"say": "Under Records, click Warranties. Each warranty shows its serial number and expiry, and what expires soon is highlighted.", "cap": "Records → Warranties", "do": [("click", "#nav a[href='#warranties']"), ("hl", "#view .tw tbody tr")]},
    {"say": "Warranties are added automatically when you enter a warranty item in the In Register. To change one, click the blue edit button.", "cap": "Edit with the blue pencil button", "do": [("hl", "#view .tw tbody tr [data-act=warranty]")]},
    {"say": "Telegram also sends reminders at thirty, fifteen, seven, three and one day before a warranty expires.", "cap": "Reminders 30, 15, 7, 3, 1 days before", "do": [("wait", 300)]},
])

V["settings"] = ("settings-telegram", None, [
    {"say": "In this video, you will learn how to connect Telegram notifications. This is for the Admin.", "cap": "Telegram Settings", "num": False},
    {"say": "Under Masters, click Settings.", "cap": "Click Masters → Settings", "do": [("click", "#nav a[href='#settings']")]},
    {"say": "Tick Send Telegram notifications. Paste the bot token from BotFather, and the main group chat ID.", "cap": "Tick Send, add Bot Token and Main Group Chat ID", "do": [("click", "#setForm input[name=telegramOn]"), ("hl", "#setForm input[name=telegramToken]"), ("hl", "#setForm input[name=telegramChat]")]},
    {"say": "Choose which messages to send, and the time for the daily messages.", "cap": "Choose Messages and Daily Time", "do": [("hl", "#setForm .evgrid"), ("hl", "#setForm input[name=dailyTime]")]},
    {"say": "Click Save Settings, then Send a Test to check the group receives it.", "cap": "Save Settings, then Send a Test", "do": [("hl", "#setForm button[type=submit]"), ("hl", "#setForm [data-act=testNotify]")]},
])

ORDER = ["signin", "start", "purchase", "opening", "warranty_in", "bulk", "transfer", "warranty_out", "repair", "damage", "receive", "dmg_approve", "repair_return", "cancel", "inventory", "warranties", "settings"]

if __name__ == "__main__":
    if not os.environ.get("EXPORT"):
        base_setup()
    for k in sys.argv[1:] or ORDER:
        name, setup, steps = V[k]
        if setup and not os.environ.get("EXPORT"):
            setup()
        record(name, steps[0]["cap"], steps, login=(k == "signin"))
