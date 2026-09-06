import os
import qrcode
from PIL import Image

# Link do CDC (Planalto)
url_destino = "https://www.planalto.gov.br/ccivil_03/leis/l8078compilado.htm"

# Caminho relativo para a imagem no seu projeto
caminho_logo = "./img/logo.png"

# 1. Gerar QR Code com alta tolerância a falhas (Nível H)
qr = qrcode.QRCode(
    version=1,
    error_correction=qrcode.constants.ERROR_CORRECT_H,
    box_size=10,
    border=4,
)
qr.add_data(url_destino)
qr.make(fit=True)

img_qr = qr.make_image(fill_color="black", back_color="white").convert("RGB")

# 2. Carregar a logo local e sobrepor no centro
if os.path.exists(caminho_logo):
    try:
        logo = Image.open(caminho_logo)

        # Redimensionar a logo para ~20% da largura do QR Code
        qr_width, qr_height = img_qr.size
        max_logo_size = qr_width // 5
        logo.thumbnail((max_logo_size, max_logo_size), Image.Resampling.LANCZOS)

        # Calcular centralização
        logo_pos = (
            (qr_width - logo.width) // 2,
            (qr_height - logo.height) // 2,
        )

        # Preservar transparência (canal Alpha/RGBA) se a logo for PNG transparente
        if logo.mode == "RGBA":
            img_qr.paste(logo, logo_pos, logo)
        else:
            img_qr.paste(logo, logo_pos)

        img_qr.save("qrcode_edlopes_cdc.png")
        print("✅ QR Code gerado com sucesso: qrcode_edlopes_cdc.png")

    except Exception as e:
        print(f"❌ Erro ao processar a imagem da logo: {e}")
else:
    print(
        f"⚠️ Arquivo não encontrado em '{caminho_logo}'. Verifique se o caminho da imagem está correto."
    )