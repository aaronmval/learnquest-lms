<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background-color:#eef2f8; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

  {{-- Inbox preview text --}}
  <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; font-size:1px; line-height:1px;">
    Your LearnQuest code is {{ $code }}. It expires in {{ $expiresMinutes }} minutes.
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2f8;">
    <tr>
      <td align="center" style="padding:32px 16px;">

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(30,60,114,0.10);">

          {{-- Header --}}
          <tr>
            <td align="center" bgcolor="#1e3c72" style="background-color:#1e3c72; background-image:linear-gradient(135deg,#2a5298,#1e3c72); padding:32px 24px;">
              <div style="font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif; font-size:26px; font-weight:700; letter-spacing:0.5px; color:#ffffff;">
                LearnQuest
              </div>
              <div style="font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#b8d4f1; padding-top:6px;">
                Adaptive Learning Management System
              </div>
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td style="padding:40px 40px 8px 40px; font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif; color:#333333;">
              <h1 style="margin:0 0 20px 0; font-size:22px; line-height:1.3; font-weight:600; color:#1e3c72;">
                {{ $heading }}
              </h1>
              <p style="margin:0 0 14px 0; font-size:15px; line-height:1.6; color:#333333;">
                Hi {{ $name }},
              </p>
              <p style="margin:0; font-size:15px; line-height:1.6; color:#555555;">
                {{ $intro }}
              </p>
            </td>
          </tr>

          {{-- Code --}}
          <tr>
            <td align="center" style="padding:28px 40px 8px 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td align="center" bgcolor="#f3f6fb" style="background-color:#f3f6fb; border:1px solid #d6e0f0; border-radius:12px; padding:22px 16px;">
                    <div style="font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; letter-spacing:1.5px; text-transform:uppercase; color:#7a9cc6; padding-bottom:10px;">
                      Your verification code
                    </div>
                    <div style="font-family:'Courier New',Courier,monospace; font-size:38px; line-height:1.2; font-weight:700; letter-spacing:10px; color:#1e3c72; padding-left:10px;">
                      {{ $code }}
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Expiry + safety --}}
          <tr>
            <td style="padding:20px 40px 8px 40px; font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif;">
              <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6; color:#555555; text-align:center;">
                This code expires in <strong style="color:#1e3c72;">{{ $expiresMinutes }} minutes</strong> and can only be used once.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 40px 36px 40px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="border-left:4px solid #2a5298; background-color:#f8fafd; padding:14px 16px; font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; line-height:1.6; color:#555555;">
                    <strong style="color:#333333;">Keep this code private.</strong>
                    LearnQuest staff will never ask you for it.
                    {{ $ignoreNote }}
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td align="center" style="border-top:1px solid #e0e0e0; padding:24px 40px; font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#8a94a6;">
              LearnQuest &middot; Kapitolyo High School<br>
              This is an automated message. Please do not reply to this email.
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>
