export default function handler(req, res) {
    const UPSELLS = {
        'up0': { 'value': 19.35, 'formatted': 'R$ 19,35' },
        'up1': { 'value': 19.46, 'formatted': 'R$ 19,46' },
        'up2': { 'value': 26.84, 'formatted': 'R$ 26,81' },
        'up3': { 'value': 23.90, 'formatted': 'R$ 23,90' },
        'up4': { 'value': 32.76, 'formatted': 'R$ 32,76' },
        'up5': { 'value': 49.93, 'formatted': 'R$ 49,93' },
        'up6': { 'value': 49.93, 'formatted': 'R$ 49,93' },
        'up7': { 'value': 19.46, 'formatted': 'R$ 19,46' },
        'up8': { 'value': 9.90, 'formatted': 'R$ 9,90' },
        'up9': { 'value': 76.30, 'formatted': 'R$ 76,30' },
        'up10': { 'value': 17.66, 'formatted': 'R$ 17,66' },
        'up11': { 'value': 27.90, 'formatted': 'R$ 27,90' },
        'up12': { 'value': 32.75, 'formatted': 'R$ 32,75' },
        'up13': { 'value': 25.27, 'formatted': 'R$ 25,27' },
        'up14': { 'value': 25.27, 'formatted': 'R$ 25,27' },
        'up15': { 'value': 27.90, 'formatted': 'R$ 27,90' },
        'up16': { 'value': 32.75, 'formatted': 'R$ 32,75' },
        'up17': { 'value': 39.90, 'formatted': 'R$ 39,90' }
    };

    const { upsell } = req.query;
    const targetUpsell = upsell || 'up1';

    if (!UPSELLS[targetUpsell]) {
        return res.status(400).json({
            success: false,
            error: 'Upsell inválido'
        });
    }

    res.status(200).json({
        success: true,
        upsell: targetUpsell,
        value: UPSELLS[targetUpsell].value,
        formatted: UPSELLS[targetUpsell].formatted
    });
}
