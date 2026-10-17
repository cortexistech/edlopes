# Edlopes Transportes — site institucional

Site da Edlopes Transportes (transporte fluvial na Amazônia), publicado em
**https://www.grupoednave.com.br**.

## Estrutura

| Caminho | O que é |
|---|---|
| `index.html`, `institucional.html`, `servico.html`, `sustentabilidade.html`, `codigoConduta.html`, `privacidade.html` | Páginas do site |
| `faleconosco.html` + `enviar.php` | Formulário de contato: envia por SMTP (PHPMailer) com proteção Cloudflare Turnstile |
| `css/`, `js/`, `img/`, `fonts/` | Estilos, scripts e imagens |
| `doc/` | Certificados ISO 9001 / ISO 14001 e anexos para download |
| `storage/` | Controle de limite de envios do formulário (`rate_limit.json`), bloqueado ao acesso público por `.htaccess` |
| `gerar_qrcode.py` | Gera o QR code com a logo que aponta para o Código de Defesa do Consumidor |
| `sitemap.xml`, `robots.txt` | SEO |

## Hospedagem

O site precisa de um servidor com **PHP 7.2.5 ou superior** (Apache, por causa dos `.htaccess`),
porque o formulário de contato roda em `enviar.php`.

> A cópia em `https://cortexistech.github.io/edlopes/` (GitHub Pages) só serve
> arquivos estáticos: nela o formulário de contato **não funciona** (o POST para
> `enviar.php` retorna 405).

## Configuração

1. Instale as dependências PHP:
   ```bash
   composer install
   ```
2. Copie `.env.example` para `.env` e preencha:

   | Variável | Uso |
   |---|---|
   | `SMTP_USER`, `SMTP_PASS` | Conta Google Workspace que envia o e-mail (senha de app) |
   | `TURNSTILE_SECRET` | Chave secreta do Cloudflare Turnstile (a chave pública fica no `data-sitekey` de `faleconosco.html`) |

   O `.env` nunca deve ser commitado.
3. Envie para o servidor todos os arquivos **mais a pasta `vendor/`** gerada pelo Composer.

## QR code

```bash
pip install "qrcode[pil]"
python gerar_qrcode.py
```
