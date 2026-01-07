export default async function handler(req, res) {
    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const {
        priceInCents,
        name,
        email,
        document,
        phone,
        // UTM parameters
        utm_source,
        utm_medium,
        utm_campaign,
        utm_content,
        utm_term,
        xcod,
        sck
    } = req.body;

    // Basic validation
    if (!priceInCents || !name || !email || !document || !phone) {
        return res.status(400).json({ error: 'Missing required fields' });
    }

    const apiKey = process.env.PARADISE_SECRET_KEY;
    if (!apiKey) {
        console.error('SERVER ERROR: PARADISE_SECRET_KEY not configured');
        return res.status(500).json({ error: 'Server misconfiguration' });
    }

    const amount = parseInt(priceInCents);
    const reference = `REF-${Date.now()}-${Math.floor(Math.random() * 1000)}`;

    // Construct payload for Paradise Pags
    const payload = {
        amount: amount,
        description: "Produto Digital", // Default description
        reference: reference,
        productHash: process.env.PARADISE_PRODUCT_HASH, // REQUIRED by Paradise Pags API
        customer: {
            name: name,
            email: email,
            phone: phone,
            document: document
        },
        tracking: {
            utm_source: utm_source,
            utm_medium: utm_medium,
            utm_campaign: utm_campaign,
            utm_content: utm_content,
            utm_term: utm_term,
            src: xcod, // Mapping xcod to src as per common practice if xcod is the source
            sck: sck
        }
    };

    try {
        const response = await fetch('https://multi.paradisepags.com/api/v1/transaction.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': apiKey
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (data.status === 'success') {
            // Return data in a format compatible with the frontend expectations, or update frontend to match this
            res.status(200).json({
                success: true,
                token: data.id, // Paradise API uses 'id', mapped to 'token' for legacy compatibility
                pixCode: data.qr_code,
                qrCodeUrl: data.qr_code_base64,
                valor: amount / 100,
                payment_status: 'pending'
            });
        } else {
            console.error('Paradise Pags Error:', data);
            res.status(400).json({
                success: false,
                message: data.message || 'Erro ao criar transação na Paradise Pags',
                details: data // Send full response for debugging
            });
        }

    } catch (error) {
        console.error('Fetch Error:', error);
        res.status(500).json({
            success: false,
            message: 'Erro interno ao processar pagamento'
        });
    }
}
