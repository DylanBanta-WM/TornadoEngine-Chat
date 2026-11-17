# Tornado Engine Chat

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) module: talk with Claude (in the harness) from the website, at /chat. Ask for a new tool and Claude builds it as a module in `web/modules`, following the Style Guide, and installs it.

- Messages are filtered through the broker before they reach Claude, so it only sees pseudonyms.
- Claude works in Claude Code's auto mode; anything it asks about shows Allow and Deny.
- Chats are deleted 14 days after they were last used, unless they're saved.
- Needs the Broker module, and the "Use Chat" permission (the setup admin has it).
