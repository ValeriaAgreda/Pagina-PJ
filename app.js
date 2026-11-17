import 'dotenv/config';
import { app } from './backend/src/app.js';

const port = Number(process.env.PORT ?? 3000);

app.listen(port, () => {
  console.log(`PJ Services disponible en el puerto ${port}.`);
});
