# PrestaShop (1.7.6 to 9.x) Crypto Payment Gateway: Bitcoin, USDT, USDC, ETH (Blokbay)

Accept crypto payments in PrestaShop: Bitcoin (BTC), Tether (USDT), USD Coin (USDC) and Ether (ETH) on Ethereum, BNB Smart Chain (BSC), Polygon, Base and Arbitrum. Non-custodial: every payment goes straight to your own wallet. 1% per payment, no monthly fee. Download the latest release: [Releases](../../releases/latest).

Accept Bitcoin, USDC, USDT and ETH. Funds go from the buyer straight to your wallet; Blokbay withholds 1% of each payment, no monthly fee. Full guide: `docs/readme_en.pdf`.

## Install
1. Back office > Modules > Module Manager > **Upload a module** > `blokbay-prestashop.zip` (the folder inside is `blokbay`).
2. Blokbay dashboard > **Payments on your site**: create the **secret key** (shown once), copy the **public key** and the **webhook signing secret**.
3. Module **Configure**: paste the three values and save.
4. Back in Blokbay set the webhook URL shown in the module page (`https://your-shop/module/blokbay/webhook`) and press **Send a test event** (answer `pong`).
5. Try a small real order. The cart currency must be **USD**.

## How it works
The customer chooses **Pay with crypto**. The module creates the order with the state *Awaiting crypto payment (Blokbay)*, creates the payment (`POST /api/v1/payments`, idempotent) and redirects to the Blokbay payment page. The signed webhook `payment.paid` (HMAC SHA-256, constant time, 5 minute tolerance, public key checked, amount checked) moves the order to *Payment accepted* and saves the transaction; `payment.expired` moves an awaiting order to *Canceled*.

License: MIT (see LICENSE).

## Supported networks
Payments settle on chain through the verified LuxSplit gateway contract `0xd4b637cC81D5B5115Cd0204A9ba6B60474F05e31` (same address on every network, source verified on Sourcify):

| Network | Chain ID | Assets |
|---|---|---|
| BNB Smart Chain (BSC, BNB Chain) | 56 | USDC, USDT |
| Ethereum | 1 | ETH, USDC, USDT |
| Polygon | 137 | USDC, USDT |
| Base | 8453 | USDC |
| Arbitrum | 42161 | USDC, USDT |
| Bitcoin | – | BTC (orders from 100 USD) |

On BNB Smart Chain the buyer pays with USDC or USDT (BEP-20) and the funds reach the merchant's wallet in the same transaction.
Verified source (Sourcify, chain 56): https://repo.sourcify.dev/56/0xd4b637cC81D5B5115Cd0204A9ba6B60474F05e31
The network list is also in [`networks.json`](networks.json).

