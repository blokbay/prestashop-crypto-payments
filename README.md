# Blokbay crypto payments for PrestaShop (1.7.6 to 9.x)

Accept USDT, USDC, ETH, BTC and SOL. Funds go from the buyer straight to your wallet; Blokbay withholds 1% of each payment, no monthly fee. Full guide: `docs/readme_en.pdf`.

Coins and networks on the Blokbay payment page: USDT and USDC on Ethereum, BNB Smart Chain, Arbitrum, Polygon and Solana; USDC on Base; ETH on Ethereum, Base and Arbitrum; SOL on Solana; Bitcoin from the equivalent of 100 USD. The customer pays from a connected wallet, or by address and QR code from any wallet or exchange, and can change coin and network before paying.

## Install
1. Back office > Modules > Module Manager > **Upload a module** > `blokbay-prestashop.zip` (the folder inside is `blokbay`).
2. Blokbay dashboard > **Payments on your site**: create the **secret key** (shown once), copy the **public key** and the **webhook signing secret**.
3. Module **Configure**: paste the three values and save.
4. Back in Blokbay set the webhook URL shown in the module page (`https://your-shop/module/blokbay/webhook`) and press **Send a test event** (answer `pong`).
5. Try a small real order. Cart currency: USD, EUR, GBP, CHF, JPY, CAD, AUD, SGD, HKD or AED; the customer pays in crypto at the current exchange rate. For other currencies the option is hidden and the module page shows a notice.

## How it works
The customer chooses **Pay with crypto**. The module creates the order with the state *Awaiting crypto payment (Blokbay)*, creates the payment (`POST /api/v1/payments`, idempotent) and redirects to the Blokbay payment page. The signed webhook `payment.paid` (HMAC SHA-256, constant time, 5 minute tolerance, public key checked, amount checked) moves the order to *Payment accepted* and saves the transaction; `payment.expired` moves an awaiting order to *Canceled*.

## Changelog
- 1.2.0: updated texts (USDT, USDC, ETH, BTC and SOL; payment from a wallet or an exchange). No change to how the module works.
- 1.1.0: shop currencies USD, EUR, GBP, CHF, JPY, CAD, AUD, SGD, HKD and AED.

License: MIT (see LICENSE).
