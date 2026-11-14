from rec import record
import sys
which = sys.argv[1:] or ["unit","category","uom","place","item","user"]
V = {}
V["unit"] = ("masters-unit", "Add a Unit", [
  {"say": "In this video, you will learn how to add a Unit. A unit is a hostel or a college under a Foundation.", "cap": "Add a Unit", "num": False},
  {"say": "Under Masters, click Units.", "cap": "Click Masters → Units", "do": [("click", "#nav a[href='#hostels']")]},
  {"say": "Click the blue Add Unit button.", "cap": "Click + Add Unit", "do": [("click", "#view .ph .btn.pri")]},
  {"say": "Type the unit name.", "cap": "Type the Unit Name", "do": [("type", ".dlg input[name=name]", "Lily Hostel")]},
  {"say": "Choose the Foundation this unit belongs to.", "cap": "Choose the Foundation", "do": [("pick", "foundationId", "Ajmal Foundation South")]},
  {"say": "Choose the unit type: Hostel or College.", "cap": "Choose Unit Type: Hostel or College", "do": [("seg", "unitType", "Hostel")]},
  {"say": "Add the address if you like.", "cap": "Address / Location (optional)", "do": [("type", ".dlg input[name=address]", "East campus, block 2")]},
  {"say": "Keep Active ticked and click Add Unit.", "cap": "Click Add Unit to save", "do": [("click", ".dlg button[type=submit]")]},
  {"say": "The unit is saved. Use the blue edit button to change it, or untick Active instead of deleting it.", "cap": "Saved — edit with the blue pencil button", "do": [("wait", 500), ("hl", "#view .tw tbody tr [data-act=mEdit]")]},
])
V["category"] = ("masters-category", "Add a Category", [
  {"say": "In this video, you will learn how to add a Category, and how to mark it for warranty.", "cap": "Add a Category", "num": False},
  {"say": "Under Masters, click Categories.", "cap": "Click Masters → Categories", "do": [("click", "#nav a[href='#categories']")]},
  {"say": "Click the blue Add Category button.", "cap": "Click + Add Category", "do": [("click", "#view .ph .btn.pri")]},
  {"say": "Type the category name.", "cap": "Type the Category Name", "do": [("type", ".dlg input[name=name]", "Electrical")]},
  {"say": "Tick Warranty applicable if items in this category have a warranty. These items then ask for a serial number and warranty expiry at stock in, and are issued one piece at a time.", "cap": "Tick Warranty Applicable (if items have a warranty)", "do": [("click", ".dlg input[name=warranty]")]},
  {"say": "Click Add Category to save.", "cap": "Click Add Category to save", "do": [("click", ".dlg button[type=submit]")]},
  {"say": "The category is saved, and shows whether warranty applies.", "cap": "Saved", "do": [("wait", 500), ("hl", "#view .tw tbody tr")]},
])
V["uom"] = ("masters-measuring-unit", "Add a Measuring Unit", [
  {"say": "In this video, you will learn how to add a Measuring Unit, such as Nos, Sets or Kg.", "cap": "Add a Measuring Unit", "num": False},
  {"say": "Under Masters, click Measuring Units.", "cap": "Click Masters → Measuring Units", "do": [("click", "#nav a[href='#units']")]},
  {"say": "Click the blue Add Measuring Unit button.", "cap": "Click + Add Measuring Unit", "do": [("click", "#view .ph .btn.pri")]},
  {"say": "Type the measuring unit, for example Nos.", "cap": "Type the Measuring Unit", "do": [("type", ".dlg input[name=name]", "Nos")]},
  {"say": "Click Add Measuring Unit to save.", "cap": "Click Add Measuring Unit to save", "do": [("click", ".dlg button[type=submit]")]},
  {"say": "It is saved, and can now be chosen for any item.", "cap": "Saved", "do": [("wait", 500), ("hl", "#view .tw tbody tr")]},
])
V["place"] = ("masters-damage-destination", "Add a Damage Destination", [
  {"say": "In this video, you will learn how to add a Damage Destination. This is where damaged items are sent, such as a scrap dealer.", "cap": "Add a Damage Destination", "num": False},
  {"say": "Under Masters, click Damage Destinations.", "cap": "Click Masters → Damage Destinations", "do": [("click", "#nav a[href='#places']")]},
  {"say": "Click the blue Add Destination button.", "cap": "Click + Add Destination", "do": [("click", "#view .ph .btn.pri")]},
  {"say": "Type the destination.", "cap": "Type the Destination", "do": [("type", ".dlg input[name=name]", "Scrap dealer")]},
  {"say": "Click Add Destination to save.", "cap": "Click Add Destination to save", "do": [("click", ".dlg button[type=submit]")]},
  {"say": "It is saved, and will be listed when you mark items as damaged.", "cap": "Saved", "do": [("wait", 500), ("hl", "#view .tw tbody tr")]},
])
V["item"] = ("masters-item", "Add an Item", [
  {"say": "In this video, you will learn how to add an Item. Items are the assets you count, like beds, fans and chairs.", "cap": "Add an Item", "num": False},
  {"say": "Under Masters, click Items.", "cap": "Click Masters → Items", "do": [("click", "#nav a[href='#items']")]},
  {"say": "Click the blue Add Item button.", "cap": "Click + Add Item", "do": [("click", "#view .ph .btn.pri")]},
  {"say": "Type the item name.", "cap": "Type the Item Name", "do": [("type", ".dlg input[name=name]", "Ceiling Fan 48 inch")]},
  {"say": "Choose its category. If the category is missing, the plus button next to it adds a new one.", "cap": "Choose the Category", "do": [("pick", "categoryId", "Electrical")]},
  {"say": "Choose the measuring unit.", "cap": "Choose the Measuring Unit", "do": [("pick", "unit", "Nos")]},
  {"say": "Notes are optional. Keep Active ticked and click Add Item.", "cap": "Click Add Item to save", "do": [("click", ".dlg button[type=submit]")]},
  {"say": "The item is saved and can now be used in the In Register and Out Register.", "cap": "Saved", "do": [("wait", 500), ("hl", "#view .tw tbody tr")]},
])
V["user"] = ("masters-user", "Add a User", [
  {"say": "In this video, you will learn how to add a User and choose their role.", "cap": "Add a User", "num": False},
  {"say": "Under Masters, click Users.", "cap": "Click Masters → Users", "do": [("click", "#nav a[href='#users']")]},
  {"say": "Click the blue Add User button.", "cap": "Click + Add User", "do": [("click", "#view .ph .btn.pri")]},
  {"say": "Type the full name, and the email they will use to sign in.", "cap": "Type Full Name and Email", "do": [("type", ".dlg input[name=name]", "Ramesh Kumar"), ("type", ".dlg input[name=email]", "ramesh@example.com")]},
  {"say": "Choose the role. Unit users work on one unit. Foundation users look after a Foundation's warehouse and units. Organization users see everything. Admin also manages masters and settings.", "cap": "Choose the Role", "do": [("seg", "role", "Unit")]},
  {"say": "For a Unit user, choose their unit.", "cap": "Choose the Unit", "do": [("pick", "hostelId", "Lily Hostel")]},
  {"say": "Set a password of at least eight characters, then click Add User.", "cap": "Set a Password, then Add User", "do": [("type", ".dlg input[name=password]", "lily12345"), ("click", ".dlg button[type=submit]")]},
  {"say": "The user can now sign in with their email and password.", "cap": "Saved — they can sign in now", "do": [("wait", 500), ("hl", "#view .tw tbody tr:has-text('Ramesh')")]},
])
for k in which:
    record(V[k][0], V[k][1], V[k][2])
