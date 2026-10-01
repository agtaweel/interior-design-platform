<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketplace deal approval requires OTP
    |--------------------------------------------------------------------------
    |
    | BRD v4 "Client Marketplace" — a deliberate, user-confirmed security tradeoff: a
    | marketplace client is already authenticated (Sanctum session) when approving a deal
    | (ClientDealController::approve()), so the anonymous token+OTP flow
    | (PublicProposalController, untouched by this flag) is skipped entirely for this path.
    |
    | This flag is a reversible kill-switch, not a feature toggle: flipping it to `true`
    | immediately disables the no-OTP shortcut (ClientDealController::approve() returns 501
    | instead of approving) without a business-logic redeploy, should this tradeoff ever need
    | revisiting. It does NOT turn on an alternate OTP-verification flow for logged-in clients —
    | building that is out of scope until the tradeoff is actually revisited.
    |
    */
    'marketplace_deal_requires_otp' => env('FITOUT_MARKETPLACE_DEAL_REQUIRES_OTP', false),

];
