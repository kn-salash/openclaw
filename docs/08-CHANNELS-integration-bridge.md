# 08 - Channels Integration Bridge

> **Cross-references:** [03-COMM](./03-COMM-gateway-protocol.md) | [04-FLOW](./04-FLOW-message-pipeline.md) | [09-SKILLS](./09-SKILLS-tools-ecosystem.md)

---

## Overview

Channels are the communication bridges between messaging platforms and the OpenClaw gateway. OpenClaw supports **5 built-in channels** and **39 extension channels**, all implementing a common plugin interface.

```
┌────────────────────────────────────────────────────────────────┐
│                     MESSAGING PLATFORMS                         │
│                                                                 │
│  Telegram  Discord  Slack  Signal  WhatsApp  Matrix  Teams ... │
│     │         │       │      │        │        │       │       │
│     ▼         ▼       ▼      ▼        ▼        ▼       ▼       │
│  grammY   discord  Bolt   signal  WhatsApp  matrix    Bot      │
│            .js             -cli    Web       SDK     Framework  │
└────┬────────┬───────┬──────┬────────┬────────┬───────┬─────────┘
     │        │       │      │        │        │       │
     ▼        ▼       ▼      ▼        ▼        ▼       ▼
┌────────────────────────────────────────────────────────────────┐
│                    CHANNEL PLUGIN INTERFACE                      │
│                                                                 │
│  id: ChannelId                                                  │
│  start(): Promise<void>                                        │
│  stop(): Promise<void>                                         │
│  sendMessage(sessionKey, text, opts): Promise<void>            │
│                                                                 │
│  Inbound: Platform msg → Common format → agentCommand()        │
│  Outbound: Agent result → Platform format → Platform API       │
└────────────────────────────────────────────────────────────────┘
```

---

## The Channel Plugin Interface

Every channel implements this common interface:

```typescript
interface ChannelPlugin {
  id: ChannelId;                        // "telegram", "discord", etc.
  start(): Promise<void>;              // Connect to platform
  stop(): Promise<void>;               // Disconnect gracefully
  sendMessage(
    sessionKey: string,
    text: string,
    opts?: SendMessageOptions
  ): Promise<void>;
}

// Inbound messages are dispatched to:
agentCommand({
  message: string,
  sessionKey: string,          // "{channel}:{chatId}"
  runId: string,
  messageChannel: string,
  images?: ImageContent[],
  streamParams?: AgentStreamParams,
});
```

---

## Built-in Channels

### Telegram (`src/channels/telegram/`)

**Library:** grammY

```
Key features:
├─ Long polling or webhook mode
├─ Group and DM support
├─ Media handling (photos, voice, documents)
├─ Reactions and inline keyboards
├─ Markdown formatting (Telegram-flavored)
├─ 4096 character message limit → auto-split
├─ Bot commands (/start, /help, /model, etc.)
└─ grammY auto-reconnect on disconnect
```

**Session key format:**
- DM: `telegram:{userId}`
- Group: `telegram:{chatId}`
- Group with user isolation: `telegram:{chatId}:{userId}`

**Outbound formatting:**
```
Agent response → Telegram MarkdownV2
├─ Escape special chars (_, *, [, ], etc.)
├─ Convert code blocks to Telegram format
├─ Split at paragraph boundaries if > 4096 chars
└─ Send via bot.api.sendMessage()
```

### Discord (`src/channels/discord/`)

**Library:** discord.js

```
Key features:
├─ Server (guild) and DM support
├─ Thread handling (auto-thread for long conversations)
├─ Slash commands (/ask, /model, /session)
├─ Rich embeds for formatted responses
├─ Voice channel support
├─ File/image attachments
├─ 2000 character message limit → auto-split
└─ discord.js auto-reconnect
```

**Session key format:**
- DM: `discord:{channelId}`
- Server: `discord:{guildId}:{channelId}`
- Thread: `discord:{guildId}:{threadId}`

**Outbound formatting:**
```
Agent response → Discord embed or plain message
├─ Code blocks → Discord markdown
├─ Long responses → split at 2000 chars
├─ Images → file attachments
└─ Send via channel.send() or interaction.reply()
```

### Slack (`src/channels/slack/`)

**Library:** Bolt (Socket Mode)

```
Key features:
├─ Socket Mode (no public URL needed)
├─ Thread support (conversations in threads)
├─ Block Kit formatting
├─ Interactive components (buttons, selects)
├─ App mentions (@bot) and DMs
├─ File sharing
└─ Bolt auto-reconnect
```

**Session key format:**
- DM: `slack:{teamId}:{channelId}`
- Channel: `slack:{teamId}:{channelId}`
- Thread: `slack:{teamId}:{channelId}:{threadTs}`

### Signal (`src/channels/signal/`)

**Library:** signal-cli (external process)

```
Key features:
├─ End-to-end encrypted messaging
├─ Group support
├─ Reactions
├─ Media (photos, attachments)
└─ Requires signal-cli installed and registered
```

**Session key format:**
- DM: `signal:{phoneNumber}`
- Group: `signal:group:{groupId}`

### Web Chat (`src/channels/webchat/`)

**Library:** Custom (built-in WebSocket)

```
Key features:
├─ No external dependency
├─ WebSocket-based real-time chat
├─ Built into the gateway server
├─ Used by the web UI
└─ Supports streaming responses
```

---

## Extension Channels

**Directory:** `extensions/` (39 extensions)

Extensions are npm packages that register as channel plugins:

```typescript
// extensions/matrix/index.ts
export default {
  id: "matrix",
  name: "Matrix",
  createChannel(config: ChannelConfig): ChannelPlugin {
    return new MatrixChannel(config);
  }
};
```

### Available Extensions

| Extension | Platform | Protocol |
|-----------|----------|----------|
| matrix | Matrix | matrix-js-sdk |
| whatsapp | WhatsApp | whatsapp-web.js / Baileys |
| teams | Microsoft Teams | Bot Framework |
| google-chat | Google Chat | Google API |
| mattermost | Mattermost | REST API |
| irc | IRC | irc-framework |
| line | LINE | Messaging API |
| twitch | Twitch | tmi.js |
| feishu | Feishu/Lark | Open API |
| nostr | Nostr | NIP protocol |
| zalo | Zalo | Zalo API |
| nextcloud-talk | Nextcloud Talk | REST API |
| mastodon | Mastodon | Mastodon API |
| bluesky | Bluesky | AT Protocol |
| lemmy | Lemmy | Lemmy API |
| rocket-chat | Rocket.Chat | REST/WebSocket |
| mumble | Mumble | Mumble protocol |
| xmpp | XMPP/Jabber | XMPP protocol |
| imessage | iMessage | applescript/shortcuts |
| +20 more | Various | Various |

---

## Channel → Gateway Message Translation

### Inbound (Platform → Common Format)

Each channel translates platform-native messages:

```
Platform Message                    Common Format
─────────────────                   ─────────────────
Telegram:                           {
  text: "Hello"                       channel: "telegram",
  from: { id: 123 }                  sessionKey: "telegram:123",
  chat: { id: 123 }                  sender: "John",
  photo: [...]                        text: "Hello",
                                      media: [{ type: "image", ... }],
Discord:                            }
  content: "Hello"
  author: { id: "456" }
  channel: { id: "789" }

Slack:
  text: "Hello"
  user: "U01"
  channel: "C01"
  thread_ts: "1700.0"
```

### Outbound (Common Format → Platform)

```typescript
// Agent result:
{ payloads: [{ text: "Here's the answer..." }] }

// Channel formats for delivery:
Telegram: bot.api.sendMessage(chatId, formatMarkdown(text))
Discord:  channel.send({ embeds: [buildEmbed(text)] })
Slack:    client.chat.postMessage({ channel, blocks: buildBlocks(text) })
Signal:   signal-cli send -m text phoneNumber
Web:      ws.send({ type: "message", text })
```

---

## Channel Configuration

```json
{
  "channels": {
    "telegram": {
      "botToken": "123456:ABC...",
      "mode": "polling",           // or "webhook"
      "webhookUrl": "https://...",  // for webhook mode
      "allowedChatIds": [12345, -100678]
    },
    "discord": {
      "botToken": "MTk...",
      "applicationId": "...",
      "guildIds": ["112233"],
      "autoThread": true
    },
    "slack": {
      "botToken": "xoxb-...",
      "appToken": "xapp-...",     // For Socket Mode
      "signingSecret": "..."
    },
    "signal": {
      "number": "+1234567890",
      "signalCliPath": "/usr/local/bin/signal-cli"
    }
  }
}
```

---

## Channel Lifecycle

```
Gateway starts
    │
    ├─ For each configured channel:
    │   ├─ Create channel plugin instance
    │   ├─ Call channel.start()
    │   │   ├─ Connect to platform
    │   │   ├─ Register message handlers
    │   │   └─ Log connection status
    │   └─ Add to active channels
    │
    ├─ Channel receives message
    │   ├─ Translate to common format
    │   ├─ Dispatch to agentCommand()
    │   ├─ Receive response
    │   └─ Format and send back to platform
    │
    ├─ Channel disconnects
    │   ├─ Auto-reconnect (library-specific)
    │   ├─ Health monitor detects (see 05-RESILIENCE)
    │   └─ Manual restart via WebSocket: { type: "channel.stop/start" }
    │
    └─ Gateway stops
        ├─ For each active channel:
        │   └─ Call channel.stop()
        └─ Graceful disconnection from all platforms
```

---

## Channel-Specific Tools

Some channels inject additional tools into the agent's toolset (see [09-SKILLS](./09-SKILLS-tools-ecosystem.md)):

**File:** `src/agents/channel-tools.ts`

```
Discord channel active?
    └─ Inject tools: manage_roles, create_channel, pin_message, etc.

Slack channel active?
    └─ Inject tools: set_topic, add_reaction, list_users, etc.
```

---

## Media Pipeline Integration

When channels receive media (photos, voice, documents):

```
Channel receives photo/voice/document
    │
    ├─ Download media from platform API
    │
    ├─ Process through media pipeline:
    │   ├─ Image → Vision model → text description
    │   ├─ Audio → Whisper STT → text transcript
    │   └─ Document → Text extraction → plain text
    │
    └─ Attach as ImageContent[] to agent message
```

---

## Key Source Files

| File | Purpose |
|------|---------|
| `src/channels/telegram/` | Telegram channel (grammY) |
| `src/channels/discord/` | Discord channel (discord.js) |
| `src/channels/slack/` | Slack channel (Bolt) |
| `src/channels/signal/` | Signal channel (signal-cli) |
| `src/channels/webchat/` | Built-in web chat |
| `extensions/` | 39 extension channels |
| `src/agents/channel-tools.ts` | Channel-specific tool injection |
| `src/gateway/channel-health-monitor.ts` | Channel health monitoring |
