export default async function handler(req, res) {
    const { id } = req.query;

    if (!id) {
        return res.status(400).json({ error: 'ID is required' });
    }

    const apiKey = process.env.PARADISE_SECRET_KEY;
    if (!apiKey) {
        return res.status(500).json({ error: 'Server misconfiguration' });
    }

    try {
        const response = await fetch(`https://multi.paradisepags.com/api/v1/query.php?action=get_transaction&id=${id}`, {
            method: 'GET',
            headers: {
                'X-API-Key': apiKey,
                'Content-Type': 'application/json'
            }
        });

        const data = await response.json();

        // Check if data has the status field directly or inside a data envelope
        // The docs say: { "id": 158, "status": "pending", ... }

        if (data && data.status) {
            res.status(200).json({
                success: true,
                data: {
                    mangofy_data: { // Keeping structure compatible with legacy frontend code for now, or we can refactor frontend
                        data: {
                            payment_status: data.status,
                            payment_code: data.id
                        }
                    }
                }
            });
        } else {
            res.status(404).json({
                success: false,
                status: 'error',
                message: 'Transaction not found or invalid response'
            });
        }

    } catch (error) {
        console.error('Error checking status:', error);
        res.status(500).json({
            success: false,
            status: 'error',
            message: 'Internal server error'
        });
    }
}
