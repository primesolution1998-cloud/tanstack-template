# Snake Alphabet Game (Jetpack Compose + Canvas)

## Step 1 (Only): Create the Android project + runnable game screen skeleton

Aaj ke hisaab se best base: **Jetpack Compose + Canvas** with a small game loop.  
Is step ke end tak app run hogi aur blank game board + moving snake head ka basic skeleton dikh jayega.

---

### 1) New project banao
- Android Studio → **New Project**
- Template: **Empty Activity (Compose)**
- Name: `SnakeAlphabet`
- Language: **Kotlin**
- Min SDK: **24+**

---

### 2) `MainActivity.kt` ko is code se replace karo

```kotlin
package com.example.snakealphabet

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import kotlinx.coroutines.delay

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { SnakeGameScreen() }
    }
}

@Composable
fun SnakeGameScreen() {
    var head by remember { mutableStateOf(Offset(240f, 240f)) }
    var direction by remember { mutableStateOf(Offset(8f, 0f)) } // right move

    LaunchedEffect(Unit) {
        while (true) {
            head += direction
            delay(80)
        }
    }

    Canvas(
        modifier = Modifier
            .fillMaxSize()
            .background(Color(0xFF0B1020))
    ) {
        // game boundary
        drawRect(
            color = Color(0xFF4FD1C5),
            style = Stroke(width = 4f)
        )

        // snake head (starter)
        drawCircle(
            color = Color(0xFF8B5CF6),
            radius = 20f,
            center = head
        )
    }
}
```

---

### 3) Run and verify
Expected output:
- dark background
- cyan border
- purple snake head right side move karta hua

Agar yeh chal gaya, next step me hum:
1. snake body + controls,
2. moving alphabets,
3. ascending order eat logic (A→Z),
4. corner-touch fail condition,
5. `D,H,L,P,T,X,Z` par ⭐ blast animation
add karenge.

> Aapne bola "one time one step only", so yahi **Step 1** diya hai.
