from rec import record
steps = [
  {"say": "In this video, you will learn how to add a Foundation. Every Foundation gets its own Central Warehouse automatically.", "cap": "Add a Foundation", "num": False},
  {"say": "In the left menu, under Masters, click Foundations.", "cap": "Click Masters → Foundations", "do": [("click", "#nav a[href='#foundations']")]},
  {"say": "Click the blue Add Foundation button at the top right.", "cap": "Click + Add Foundation", "do": [("click", "#view .ph .btn.pri")]},
  {"say": "Type the Foundation name.", "cap": "Type the Foundation Name", "do": [("type", ".dlg input[name=name]", "Ajmal Foundation South")]},
  {"say": "If this Foundation has its own Telegram group, enter the group ID. You can leave it blank.", "cap": "Telegram Group ID (optional)", "do": [("hl", ".dlg input[name=tgChat]")]},
  {"say": "Keep Active ticked, then click Add Foundation to save.", "cap": "Click Add Foundation to save", "do": [("hl", ".dlg input[name=active]"), ("click", ".dlg button[type=submit]")]},
  {"say": "The Foundation is added, together with its Central Warehouse.", "cap": "Saved — Central Warehouse created automatically", "do": [("wait", 600), ("hl", "#view .tw tbody tr")]},
  {"say": "To change a Foundation later, click the blue edit button on its row, make your change, and click Save.", "cap": "Edit: click the blue pencil button", "do": [("hl", "#view .tw tbody tr [data-act=mEdit]")]},
]
record("masters-foundation", "Add a Foundation", steps)
