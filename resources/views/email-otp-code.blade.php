<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding: 32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding: 32px;">
                            <p style="margin:0 0 16px 0; color:#111827; font-size:15px;">
                                Voici votre code de connexion :
                            </p>
                            <div style="margin: 0 0 20px 0; background-color:#f4f4f5; border-radius:6px; padding: 16px 0; text-align:center;">
                                <span style="font-size:32px; font-weight:700; letter-spacing:8px; color:#111827;">
                                    {{ $code }}
                                </span>
                            </div>
                            <p style="margin:0; color:#6b7280; font-size:13px; line-height:1.5;">
                                Ce code expire dans quelques minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
