# Blokbay crypto payments for PrestaShop (1.7.6 to 9.x)

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
