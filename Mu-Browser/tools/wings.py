# Baja de guides.zigzagmu.ru las imágenes de las alas de 3.er nivel, las capas y los retratos de
# Magic Gladiator y Dark Lord, y las deja como GIF del tamaño que usa el juego.
# Uso: wings.py <carpeta de descarga> <carpeta public/assets/img>
import io, os, sys, urllib.request
from PIL import Image, ImageDraw

RAW, OUT = sys.argv[1], sys.argv[2]
os.makedirs(RAW, exist_ok=True)
UA = {'User-Agent': 'Mozilla/5.0'}
B = 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/'

# destino → (celdas de ancho, de alto, URL). Con ancho 0, el alto es el lado en píxeles de una imagen cuadrada (retratos, monstruos).
FILES = {
    'items/8/darkness_wings.gif': (5, 3, B + 'AVvXsEiXcLupF_GjeHuEwPHrX4682lKDRo7O3TU2Cs2MvIMR_Du3uK8y0YwciEtAdMnFJLUmiqJ_Z7ZSnCJJ6QV6I5YLAd6-aF5yVzq9fynmh_J3Rri8LtlBZPqIKI4JhmxvZsJghc-vVVF_22aw/h120/Wings+of+Darkness.png'),
    'items/8/cape_of_lord.gif': (2, 3, B + 'AVvXsEhLNi0zlTfudyVQuaRevEQTeKLDPaiIx-iKjdHnHnC13gI0N9Ifekxf6HjN33M8vks7p46vBJsFQX8oAQ9xxMKD7bkGqQbMKRjWrmJhY6yhCUJnLQm89JTHPglRGyh6R2dvdUwkrxs8gd6o/h120/Cape+of+Lord.png'),
    'items/8/wing_of_storm.gif': (5, 3, B + 'AVvXsEhuDL_yvPhUC16DKV1JiTUeIuUvLxq1doDKzQMtp99-DVkCK9VKJ8JNTK76G2IOixCVViOEjUW73QeAeHSCWrmkv9V-Kj0sfOZ4i4eRfSpBVw57HbsLrdFmmUl8Hq79PFxSc8S5Ktm0clEi/h120/Wing+of+Storm.png'),
    'items/8/wing_of_eternal.gif': (5, 3, B + 'AVvXsEhzGPMsrlcQWxikNl-KPom2P6Vwed-IrVeysu_maI5pbOAs94kzYXO1uz4zit-gy2I8Z9LULQLxJxhJk5Lz-wVM6YOdtuEhyphenhyphenlpiMh2LOuXaYruKn9YUlvw41v9oqqVsfT12pMEX6wqxvMTp/h120/Wing+of+Eternal.png'),
    'items/8/wing_of_illusion.gif': (5, 3, B + 'AVvXsEj6WAMBUZHMVUPJeYuP8plYOJp5fZzM-1E4jfvkdoc3DIiMD1YFc-83wGgVTuCFJgkWmA_aXgfp_dVJHiV3pTO9Xr5hfJiGMVV3rajzQQwRihXL-GjA3sISsTiNohLqUgn1u_GjmWaoVq55/h120/Wing+of+Illusion.png'),
    'items/8/wing_of_dimension.gif': (5, 3, B + 'AVvXsEi8recPV_ysRTDygIKw9R0pTQOEGLF7V3ZN9CSRfn64GLb0PPQHKHYkgt0RgeKeKMvFOSY6_wHJLIvlfQ_AcymdeOb7bBherGSPN18KAwV8keA2irvmeStbbRAhgB9Cn-9PpsiJV90xBW51/h120/Wing+of+Dimension.png'),
    'items/8/wing_of_ruin.gif': (5, 3, B + 'AVvXsEjOxhqL9zbgXAlrHZtBe9vJF0IAJWzERs7WLhD7b2k-W2Wt3a9VXCg1H_1aCq8EbZ2aKPS7T-o-VGxBwDzfwAfdf-CkXbJ1wbLaOtfWkcEH4JMzJpiMkdNHtJZdHHrGDH7BHbnK_6B1Xss8/h120/Wing+of+Ruin.png'),
    'items/8/cape_of_emperor.gif': (2, 3, B + 'AVvXsEivi1_Cb2uD7moG-lUO2rMRMqw-n8PyFEpO0CTfc6DIoW7E0t3vLk6THOr_xNLFAFBZtYdcTfgOVU2UJw1CV0eASPQwmtoaMoSbgRjedpxuSUuHDvFAK1_Sp57N0sPSrLgR9ovhqluJyUbE/h120/Cape+of+Emperor.png'),
    'classes/5.gif': (0, 100, B + 'AVvXsEg6KFDuuQW-3Qi9DHSmTvXxVit9Z5GGjns5sKbJjr7BPVm4lMmwV8UGvBMIVuVm27rsiNjpf5lQZWEltM5LePJLFh77QDCBXeqPFanTRVKODdsXBasOvojYOtwY2VRL7Q0MIxob2HZ7dfCG/h250/Magic+Gladiator.jpg'),
    'classes/6.gif': (0, 100, B + 'AVvXsEjQkA48RH4TP_nx75ANYq-rVLe7yNDlm5ME01ZC9R043Qd51Mp_KJ4Bd9HT99s1G0BAdT6Fp7f7bZ8SJcRSKPh2OJTPIxZpzjAWiE9I_dIWWufIbuwv4RlBeGev5XD9NQ4Coev3FzDyMsu5/h250/Dark+Lord.jpg'),
    'items/C/dark_horse.gif': (1, 1, B + 'AVvXsEinqbMgFyatGLMw5DZVFrb7-VM7COqtpYqO_lPawbAm7mH9wMEi7Bcz4qr4q7yowa3MSTknJjTGybG3x4dbFufURZSdEb7LBG9haf4MfzL38z8ln2DSPOrM72YAv6lLHqr42YfFi-hOEG80/h120/Dark+Horse.png'),
    'items/C/dark_raven.gif': (1, 1, B + 'AVvXsEgU0jh2RwDpegV_OTtr-nJo6YUGC1Dj_k-0mBOtIlqYjf2ROBgQi7gIUE_AeTjI97ga1YOj5Lhh2wAGqwJkR1qoFpFm1BCCBRodglKfsN0qbIiNkaVSkxIG6nrETYuanEuwOqw-yI8oIRZX/h120/Dark+Raven.png'),
    'monsters/selupan.gif': (0, 64, B + 'AVvXsEgSvyNeWh0JmWDqY2_QZVr1Kw-xkvb6b8WDD_NTOcK0vwS6cXutZ2jQVGNm0PlnqDTc2I_b2RXnd6yPfL1f2TCCFgBSPU7GEvuHi_yLMqKt7igUSc9MGYJ1Gnlx1A83B8quI9Zpa5TX3Eg8/w140-h140-p/Selupan.jpg'),
    'monsters/medusa.gif': (0, 64, B + 'AVvXsEgJt3orLp5fOCAy0CN9YFfMAguxegkUQ87p-xrpixtBIMjRYRdHEx2lVVfI2LNcc6HsJy2oBRH7DAJGeI_JFBa99TyD7tNRzL0oZcTpepZXBmC4Ojt7dB9aGLtcUioYZX6v9PSWUNwtdoVt/w140-h140-p/Medusa.jpg'),
    'monsters/nightmare.gif': (0, 64, B + 'AVvXsEjDLJ-Gy3kZZ7ZtiUqBQ3TVZzNkpe20BDxOiVWTItAILSiNVOyYKkWY1Zdhyphenhyphen1m5yNWctCN7klYjQaitTemt7b3LkcwpdgjiRusrn_6aIJIzLbWlqT_2HQBtd29U48C-dTY8d-awpoKLIQxS/w140-h140-p/Nightmare.jpg'),
    'monsters/lord_of_ferea.gif': (0, 64, B + 'AVvXsEiC0SayOo8SWmEr9tXOYkUxBI-7P1pxuBZ-BYDbtnXwdNaGIe22nNdXVmn_s5WobAvhdGpxamzDPIx9504CP-cXYtc7SmT0hw2_mo3bBbjObfvV7ch_AkUs-GWUKbf2ITmHZO-4MiRLQPr1/w140-h140-p/Lord+of+Ferea.jpg'),
    'monsters/nix.gif': (0, 64, B + 'AVvXsEgmhoIt6D00gHWqPI0SJ3ApIHLHJtP-8tZwMqQ_g1SpKuDd1jRPZMM7xsjRIBpQotvE7XF6GZugNXyuXHF7OrgLKkgXhP-bxN_otr1JPoVIl7jVKdyvL6lF6wVfXEBHPGu_NfYVB5L2ToyO/w93-h140-p/Nix.jpg'),
}

for dest, (w, h, url) in FILES.items():
    raw = os.path.join(RAW, dest.replace('/', '_') + os.path.splitext(url)[1])
    if not os.path.exists(raw):
        with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60) as r:
            open(raw, 'wb').write(r.read())
    im = Image.open(raw)
    if w == 0:
        out = im.convert('RGB').resize((h, h), Image.LANCZOS)
        out.quantize(colors=255).save(os.path.join(OUT, dest))
    else:
        # La imagen de origen trae el ítem sobre un recuadro gris con marco: se saca el marco y se
        # vuelve transparente el gris, rellenando desde los bordes para no comerse los grises del ítem.
        inset = max(8, im.width // 6) if im.width < 100 else 8  # las miniaturas traen el marco más adentro
        rgb = im.convert('RGB').crop((inset, inset, im.width - inset, im.height - inset))
        key = (255, 0, 255)
        for x in range(0, rgb.width, 4):
            for y in (0, rgb.height - 1):
                if rgb.getpixel((x, y)) != key:
                    ImageDraw.floodfill(rgb, (x, y), key, thresh=40)
        for y in range(0, rgb.height, 4):
            for x in (0, rgb.width - 1):
                if rgb.getpixel((x, y)) != key:
                    ImageDraw.floodfill(rgb, (x, y), key, thresh=40)
        rgba = rgb.convert('RGBA')
        rgba.putdata([(r, g, b, 0) if (r, g, b) == key else (r, g, b, 255) for r, g, b, _ in rgba.getdata()])
        rgba = rgba.crop(rgba.getchannel('A').getbbox())
        scale = min(w * 32 / rgba.width, h * 32 / rgba.height)
        rgba = rgba.resize((max(1, round(rgba.width * scale)), max(1, round(rgba.height * scale))), Image.LANCZOS)
        canvas = Image.new('RGBA', (w * 32, h * 32), (0, 0, 0, 0))
        canvas.paste(rgba, ((w * 32 - rgba.width) // 2, (h * 32 - rgba.height) // 2))
        # GIF con transparencia: el índice 255 queda reservado para el fondo
        alpha = canvas.getchannel('A')
        pal = canvas.convert('RGB').quantize(colors=255)
        pal.paste(255, alpha.point(lambda a: 255 if a < 128 else 0))
        pal.save(os.path.join(OUT, dest), transparency=255)
    print(dest, os.path.getsize(raw), im.size, im.mode)
