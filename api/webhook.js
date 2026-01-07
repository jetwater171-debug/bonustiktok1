export default async function handler(req, res) {
    if (req.method !== 'POST') {
        return res.status(405).send('Method Not Allowed');
    }

    try {
        const event = req.body;

        console.log('Webhook Received:', JSON.stringify(event));

        // Here you would implement logic to update your database or trigger other actions
        // Since we are moving away from SQLite and just trusting the API check for the frontend flow,
        // this is mostly for logging or future expansions (like email sending, robust tracking, etc.)

        res.status(200).json({ received: true });
    } catch (error) {
        console.error('Webhook Error:', error);
        res.status(500).send('Internal Server Error');
    }
}
